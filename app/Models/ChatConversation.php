<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One chat session (website or Telegram bot). Messages are stored as
 * [{role: user|assistant, content: string, answered?: bool}]; media a Telegram
 * customer sends is stored without content as {role: user, type, file_id}, and
 * replies staff send from the admin panel as {role: admin, content, author}.
 * Telegram bot chats record every incoming message (message_id, at); registration steps, commands
 * and taps that got no AI answer carry aside: true.
 * Recorded (unanswered) chats — channel business / group — store
 * {role: user|owner|member, sender, message_id, at, content | type+file_id+caption?, edited?, deleted?}.
 */
class ChatConversation extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    /** Conversation types: where the chat took place. */
    public const CHANNELS = ['web' => 'Website', 'telegram' => 'Telegram bot', 'business' => 'Telegram Business', 'group' => 'Telegram groups'];

    protected $fillable = [
        'uuid', 'channel', 'external_id', 'business_connection_id', 'locale', 'page', 'title', 'messages', 'message_count', 'unanswered_count', 'chat_lead_id',
    ];

    /** Chats a person can answer in Telegram: the bot (staff reply from the panel) and the Premium account. */
    public const REPLY_CHANNELS = ['telegram', 'business'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'awaiting_reply' => 'boolean', 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $conversation) {
            if (in_array($conversation->channel, self::REPLY_CHANNELS, true)
                && ($conversation->isDirty('messages') || $conversation->isDirty('handled_count') || ! $conversation->exists)) {
                $conversation->forceFill($conversation->replyState());
            }
        });
    }

    /**
     * Whether the customer is waiting: the last message (deleted ones ignored) is theirs — not a
     * bot step the bot already handled — and staff have not marked the chat answered since.
     *
     * @return array{awaiting_reply: bool, last_message_at: Carbon|null}
     */
    public function replyState(): array
    {
        // Deleted messages, late edits (of a message from an older session), the bot's registration
        // questions and the customer's answers to them (bot steps) don't change who is waiting.
        $relevant = collect($this->messages ?? [])->reject(fn ($m) => ! empty($m['deleted']) || ! empty($m['late_edit'])
            || ($m['role'] ?? null) === 'bot' || ! empty($m['aside']))->values();
        $last = $relevant->last();
        $awaiting = in_array($this->channel, self::REPLY_CHANNELS, true)
            && ($last['role'] ?? null) === 'user'
            && $this->countedMessages() > (int) $this->handled_count;

        // Waiting since the first of the customer's unanswered messages; otherwise the last message.
        $since = $last;
        if ($awaiting) {
            foreach ($relevant->reverse() as $m) {
                if (($m['role'] ?? null) !== 'user' || ! empty($m['aside'])) {
                    break;
                }
                $since = $m;
            }
        }
        $at = isset($since['at']) && is_string($since['at']) ? rescue(fn () => Carbon::parse($since['at']), null, false) : null;

        return [
            'awaiting_reply' => $awaiting,
            'last_message_at' => $at ?? ($last ? ($this->updated_at ?? now()) : null),
        ];
    }

    /** Messages that count as new for "Mark answered" (late edits do not). */
    private function countedMessages(): int
    {
        return collect($this->messages ?? [])->reject(fn ($m) => ! empty($m['late_edit']))->count();
    }

    /** Waiting chats, only the latest session of each chat (a newer session supersedes older ones). */
    public function scopeAwaitingReply(Builder $query): void
    {
        $query->where('awaiting_reply', true)
            ->whereIn('channel', self::REPLY_CHANNELS)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('chat_conversations as newer')
                ->whereColumn('newer.channel', 'chat_conversations.channel')
                ->whereColumn('newer.external_id', 'chat_conversations.external_id')
                ->whereRaw("coalesce(newer.business_connection_id, '') = coalesce(chat_conversations.business_connection_id, '')")
                ->whereColumn('newer.id', '>', 'chat_conversations.id'));
    }

    /** Staff answered outside the panel / Telegram (e.g. by phone): off the list until the customer writes again. */
    public function markHandled(): void
    {
        $this->handled_count = $this->countedMessages();
        $this->timestamps = false;
        $this->save();
        $this->timestamps = true;
    }

    /** Channels where the assistant answers (and client requests come from); the others are only recorded. */
    public static function answeredChannels(): array
    {
        return array_intersect_key(self::CHANNELS, array_flip(['web', 'telegram']));
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(ChatLead::class, 'chat_lead_id');
    }

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'external_id', 'telegram_id');
    }

    /**
     * The dialogue with the assistant: text messages, without media and without "aside" entries
     * (Telegram registration steps, commands and button taps — recorded, but not questions).
     */
    public function textMessages(): array
    {
        return array_values(array_filter($this->messages ?? [], fn (array $m) => isset($m['content']) && empty($m['aside'])));
    }

    /**
     * Record a message as it arrives (Telegram bot): {content} or {type, file_id, caption?}, plus
     * message_id and time. Text starts as "aside"; appendReply() makes it a question once answered.
     * $aside also marks non-text (e.g. the phone shared during registration) as a bot step, not a file.
     */
    public function appendIncoming(array $entry, bool $aside = false, bool $awaitsReply = true): void
    {
        // Record-only mode ($awaitsReply false): a plain customer message, nothing is pending.
        $aside = $aside || ($awaitsReply && isset($entry['content']));
        $this->messages = [...$this->freshMessages(), ['role' => 'user'] + $entry + ($aside ? ['aside' => true] : [])];
        $this->message_count = count($this->messages);
        $this->save();
    }

    /** The assistant answered the recorded message $messageId (added as a question if it is not there). */
    public function appendReply(?int $messageId, string $question, string $reply, bool $answered): void
    {
        $messages = $this->freshMessages();
        $found = false;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $m = $messages[$i];
            // Telegram always sends message_id; without one, the latest recorded aside text is ours.
            $ours = $messageId !== null
                ? ($m['message_id'] ?? null) === $messageId
                : ! empty($m['aside']) && isset($m['content']) && ($m['message_id'] ?? null) === null;
            if ($ours && ($m['role'] ?? null) === 'user') {
                unset($messages[$i]['aside']);
                // The dialogue keeps the question the AI saw (button emoji removed); the raw text stays alongside.
                if ($m['content'] !== $question) {
                    $messages[$i]['raw'] = $m['content'];
                    $messages[$i]['content'] = $question;
                }
                $found = true;
                break;
            }
        }
        if (! $found) {
            $messages[] = ['role' => 'user', 'content' => $question];
        }
        $messages[] = ['role' => 'assistant', 'content' => $reply, 'answered' => $answered];

        $this->messages = $messages;
        $this->message_count = count($messages);
        if (! $answered) {
            $this->unanswered_count++;
        }
        $this->save();
    }

    /**
     * A recorded voice message $messageId was transcribed: its text becomes the message content (kept beside
     * type and file_id, so admins still see the player). Pending like typed text until appendReply() answers it.
     */
    public function setTranscript(int $messageId, string $text): bool
    {
        $messages = $this->freshMessages();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['message_id'] ?? null) === $messageId && ($messages[$i]['role'] ?? null) === 'user' && isset($messages[$i]['type'])) {
                $messages[$i] = ['content' => $text, 'transcribed' => true, 'aside' => true] + $messages[$i];
                $this->messages = $messages;
                $this->save();

                return true;
            }
        }

        return false;
    }

    /** The sender edited message $messageId: replace its content. Returns false when it is not stored here. */
    public function applyEdit(int $messageId, array $content): bool
    {
        $messages = $this->freshMessages();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['message_id'] ?? null) === $messageId) {
                // A voice message's transcript stays when only its caption is edited.
                $keep = array_intersect_key($messages[$i], array_flip(['role', 'sender', 'message_id', 'at', 'aside',
                    ...(! empty($messages[$i]['transcribed']) && isset($content['type']) ? ['content', 'transcribed'] : [])]));
                $messages[$i] = $keep + $content + ['edited' => true];
                $this->messages = $messages;
                $this->timestamps = false; // an edit does not keep the session open
                $this->save();
                $this->timestamps = true;

                return true;
            }
        }

        return false;
    }

    /** Messages as stored now: another webhook may have added some while the AI was answering. */
    private function freshMessages(): array
    {
        if ($this->exists && ($stored = static::whereKey($this->getKey())->value('messages'))) {
            return is_array($stored) ? $stored : (json_decode($stored, true) ?: []); // value() applies the array cast
        }

        return $this->messages ?? [];
    }

    public function prunable(): Builder
    {
        return static::where('updated_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** Append one visitor question and the assistant's reply. */
    public function appendExchange(string $question, string $reply, bool $answered): void
    {
        $messages = $this->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $question];
        $messages[] = ['role' => 'assistant', 'content' => $reply, 'answered' => $answered];

        $this->messages = $messages;
        $this->message_count = count($messages);
        if (! $answered) {
            $this->unanswered_count++;
        }
        $this->save();
    }

    /** A message a BDB staff member sent to the customer from the admin panel (Telegram only). */
    public function appendAdminMessage(string $text, string $author): void
    {
        $messages = $this->messages ?? [];
        $messages[] = ['role' => 'admin', 'content' => $text, 'author' => $author];

        $this->messages = $messages;
        $this->message_count = count($messages);
        $this->save();
    }
}
