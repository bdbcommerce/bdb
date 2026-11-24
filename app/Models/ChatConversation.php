<?php

namespace App\Models;

use App\Services\SalesAssistant\SalesAssistant;
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
    public const CHANNELS = ['web' => 'Website', 'telegram' => 'Telegram', 'business' => 'Telegram Business (before merge)', 'group' => 'Telegram groups'];

    protected $fillable = [
        'uuid', 'channel', 'external_id', 'business_connection_id', 'locale', 'page', 'title', 'messages', 'message_count', 'unanswered_count', 'chat_lead_id', 'sales_state',
    ];

    /** Chats a person can answer in Telegram: the bot (staff reply from the panel) and the Premium account. */
    public const REPLY_CHANNELS = ['telegram', 'business'];

    /**
     * One Telegram customer = one conversation (channel telegram), whichever chat they write in: each message
     * carries `via` — "bot" (the bot chat; the default when missing) or "assistant" (a Premium account's chat,
     * with `connection` = its business_connection_id). Message IDs are numbered per chat, so a message is
     * matched by via + message_id. Older Premium-account sessions (channel business) stay as they were.
     */
    public const VIA = ['bot' => 'Bot', 'assistant' => 'Assistant'];

    /** The chat message $m came through: bot | assistant. */
    public function viaOf(array $m): string
    {
        return in_array($m['via'] ?? null, ['bot', 'assistant'], true) ? $m['via'] : ($this->channel === 'business' ? 'assistant' : 'bot');
    }

    /** The chat the customer wrote in last (answers go there): bot | assistant. */
    public function via(): string
    {
        // Nobody on the customer side wrote yet (e.g. the account owner started the chat): the chat of the last message.
        $last = $this->lastCustomerMessage() ?? collect($this->messages ?? [])->last(fn ($m) => is_array($m) && empty($m['late_edit']));

        return $this->viaOf($last ?? []);
    }

    /** The Premium account (business_connection_id) the customer last wrote to, when that was an assistant chat. */
    public function viaConnectionId(): ?string
    {
        $m = $this->lastCustomerMessage();
        if ($m && $this->viaOf($m) === 'assistant' && (is_string($m['connection'] ?? null) || $this->channel === 'business')) {
            return is_string($m['connection'] ?? null) && $m['connection'] !== '' ? $m['connection'] : $this->business_connection_id;
        }
        foreach (array_reverse($this->messages ?? []) as $m) {
            if (is_array($m) && is_string($m['connection'] ?? null) && $m['connection'] !== '') {
                return $m['connection'];
            }
        }

        return $this->channel === 'business' ? $this->business_connection_id : null;
    }

    /** The settings channel the customer's last chat belongs to: telegram (bot) | business (Premium account). */
    public function modeChannel(): string
    {
        return $this->via() === 'assistant' ? 'business' : 'telegram';
    }

    private function lastCustomerMessage(): ?array
    {
        foreach (array_reverse($this->messages ?? []) as $m) {
            if (is_array($m) && ($m['role'] ?? null) === 'user' && empty($m['deleted']) && empty($m['late_edit'])) {
                return $m;
            }
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'sales_state' => 'array', 'messages' => 'array', 'awaiting_reply' => 'boolean', 'last_message_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(function (self $conversation) {
            if (! in_array($conversation->channel, self::REPLY_CHANNELS, true) || (! $conversation->wasChanged('messages') && ! $conversation->wasChanged('handled_count'))) {
                return;
            }
            $last = collect($conversation->messages ?? [])->last(fn ($m) => is_array($m) && empty($m['deleted']) && empty($m['late_edit']));
            if ($conversation->wasChanged('handled_count') || in_array($last['role'] ?? null, ['owner', 'admin'], true)) {
                SalesAssistant::staleDrafts($conversation);
            }
        });

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

        // A customer message is answered by any later message of a person (a manager read the whole
        // conversation), or by a later AI reply in the same chat (bot chat / that Premium account) —
        // an AI answer in the bot chat does not answer a question sent to the Premium account.
        $chat = fn (array $m) => $this->viaOf($m).'|'.($this->viaOf($m) === 'assistant' ? ($m['connection'] ?? $this->business_connection_id ?? '') : '');
        $unanswered = null;
        $answeredChats = [];
        $personLater = false;
        foreach ($relevant->reverse() as $m) {
            $role = $m['role'] ?? null;
            if ($role === 'user') {
                if (! $personLater && ! isset($answeredChats[$chat($m)])) {
                    $unanswered = $m; // keeps the earliest one
                }
            } elseif (in_array($role, ['owner', 'admin'], true)) {
                $personLater = true;
            } elseif (empty($m['handoff']) && ! self::isCourtesy($m)) { // the hand-off line, the off-hours note and the greeting answer nothing
                $answeredChats[$chat($m)] = true;
            }
        }
        $awaiting = in_array($this->channel, self::REPLY_CHANNELS, true)
            && $unanswered !== null
            && $this->countedMessages() > (int) $this->handled_count;

        // Waiting since the first of the customer's unanswered messages; otherwise the last message.
        $since = $awaiting ? $unanswered : $last;
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
            ->whereNotExists(fn ($q) => self::newerSession($q->selectRaw('1')->from('chat_conversations as newer')->whereIn('newer.channel', self::REPLY_CHANNELS)));
    }

    /**
     * Subquery condition: `newer` is a later session of the same chat as `chat_conversations` (for a customer: the
     * bot chat, the merged conversation and older Premium sessions alike). One total order, so exactly one session is
     * the newest: updated last (new messages always go to the session updated last), then the merged conversation
     * (channel telegram), then the higher id.
     */
    public static function newerSession($q): mixed
    {
        return $q->whereColumn('newer.external_id', 'chat_conversations.external_id')
            ->where(fn ($q) => $q->whereColumn('newer.channel', 'chat_conversations.channel')
                ->orWhere(fn ($q) => $q->whereIn('newer.channel', self::REPLY_CHANNELS)->whereIn('chat_conversations.channel', self::REPLY_CHANNELS)))
            ->where(fn ($q) => $q->whereColumn('newer.updated_at', '>', 'chat_conversations.updated_at')
                ->orWhere(fn ($q) => $q->whereColumn('newer.updated_at', 'chat_conversations.updated_at')
                    ->where(fn ($q) => $q->where(fn ($q) => $q->where('newer.channel', 'telegram')->where('chat_conversations.channel', '!=', 'telegram'))
                        ->orWhere(fn ($q) => $q->whereRaw("(newer.channel = 'telegram') = (chat_conversations.channel = 'telegram')")
                            ->whereColumn('newer.id', '>', 'chat_conversations.id')))));
    }

    /** The newest of these sessions of one chat, by the same order as newerSession(). */
    public static function newestOf(iterable $sessions): ?self
    {
        return collect($sessions)->sort(fn (self $a, self $b) => [$a->updated_at?->getTimestamp() ?? 0, $a->channel === 'telegram', $a->id]
            <=> [$b->updated_at?->getTimestamp() ?? 0, $b->channel === 'telegram', $b->id])->last();
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
     * Every session of the same Telegram chat, this one included. A customer's bot and Premium-account sessions
     * (older ones were stored apart, channel business) are one chat; groups by chat.
     */
    /** The assistant's off-hours note or greeting: sent, but not an answer and not a conversation turn. */
    public static function isCourtesy(mixed $m): bool
    {
        return is_array($m) && (! empty($m['off_hours']) || ! empty($m['greeting']));
    }

    public function scopeSameChatAs(Builder $query, self $conversation): void
    {
        if (in_array($conversation->channel, self::REPLY_CHANNELS, true)) {
            $query->whereIn('channel', self::REPLY_CHANNELS)->where('external_id', $conversation->external_id);

            return;
        }
        $query->where('channel', $conversation->channel)
            ->where('external_id', $conversation->external_id)
            ->whereRaw("coalesce(business_connection_id, '') = ?", [(string) $conversation->business_connection_id]);
    }

    /** The newest session of this chat (staff replies and "Mark as answered" go there). Website chats: this one. */
    public function latestSession(): self
    {
        if ($this->channel === 'web' || ! $this->external_id) {
            return $this;
        }

        return self::newestOf(self::query()->sameChatAs($this)->get()) ?? $this;
    }

    /**
     * The chat as one thread: messages of every session of this chat from the last $days days
     * (counted back from the chat's newest message), plus all of this session. Website chats: only this session.
     *
     * @return array{messages: array<int, array>, older: bool}
     */
    public function history(int $days): array
    {
        if ($this->channel === 'web' || ! $this->external_id) {
            return ['messages' => $this->messages ?? [], 'older' => false];
        }
        $sessions = self::query()->sameChatAs($this)->orderBy('id')->get();
        $newest = $sessions->map(fn (self $s) => $s->last_message_at ?? $s->updated_at)->filter()->max() ?? now();
        $cutoff = $newest->copy()->subDays($days);

        $messages = [];
        $older = false;
        foreach ($sessions as $session) {
            $own = $session->is($this);
            foreach ($session->messages ?? [] as $m) {
                try {
                    $at = isset($m['at']) ? Carbon::parse($m['at']) : null;
                } catch (\Throwable) {
                    $at = null;
                }
                if (! $own && ($at ?? $session->updated_at ?? $newest)->lt($cutoff)) {
                    $older = true;

                    continue;
                }
                $messages[] = in_array($session->channel, self::REPLY_CHANNELS, true) ? ['via' => $session->viaOf($m)] + $m : $m;
            }
        }

        return ['messages' => $messages, 'older' => $older];
    }

    /** The name shown for the chat in the admin (customer, chat title, else the source). */
    public function chatName(): string
    {
        $user = $this->channel === 'telegram' ? $this->telegramUser : null;

        return collect([$user?->displayName(), $this->title])->first(fn ($v) => filled($v))
            ?? ($this->channel === 'web' ? 'Website visitor' : (self::CHANNELS[$this->channel] ?? ucfirst((string) $this->channel)));
    }

    /**
     * Telegram IDs of the people who wrote on the customer side: [from ID => name or null].
     * Bot and Premium-account chats: the private chat ID is the customer's own ID. Groups: each member's sender_id.
     *
     * @return array<string, string|null>
     */
    public function fromIds(): array
    {
        if (! $this->external_id || $this->channel === 'web') {
            return [];
        }
        $ids = [];
        foreach ($this->messages ?? [] as $m) {
            if (isset($m['sender_id']) && in_array($m['role'] ?? null, ['user', 'member'], true)) {
                $ids[(string) $m['sender_id']] ??= $m['sender'] ?? null;
            }
        }
        if (in_array($this->channel, ['telegram', 'business'], true)) {
            $ids = [(string) $this->external_id => null] + $ids;
        }

        return $ids;
    }

    /**
     * The dialogue with the assistant: text messages, without media and without "aside" entries
     * (Telegram registration steps, commands and button taps — recorded, but not questions).
     * $via: only one chat's messages (the bot's own AI must never see the Premium-account chat).
     */
    public function textMessages(?string $via = null): array
    {
        return array_values(array_filter($this->messages ?? [], fn (array $m) => isset($m['content']) && empty($m['aside'])
            && ($via === null || $this->viaOf($m) === $via)));
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
            if ($ours && ($m['role'] ?? null) === 'user' && $this->viaOf($m) === 'bot') {
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
            if (($messages[$i]['message_id'] ?? null) === $messageId && ($messages[$i]['role'] ?? null) === 'user' && isset($messages[$i]['type']) && $this->viaOf($messages[$i]) === 'bot') {
                $messages[$i] = ['content' => $text, 'transcribed' => true, 'aside' => true] + $messages[$i];
                $this->messages = $messages;
                $this->save();

                return true;
            }
        }

        return false;
    }

    /** The sender edited bot-chat message $messageId: replace its content. Returns false when it is not stored here. */
    public function applyEdit(int $messageId, array $content): bool
    {
        $messages = $this->freshMessages();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['message_id'] ?? null) === $messageId && $this->viaOf($messages[$i]) === 'bot') {
                // A voice message's transcript stays when only its caption is edited.
                $keep = array_intersect_key($messages[$i], array_flip(['role', 'sender', 'sender_id', 'message_id', 'at', 'aside',
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
    public function appendAdminMessage(string $text, string $author, ?int $messageId = null): void
    {
        $messages = $this->messages ?? [];
        // The Telegram message ID lets a customer's reply to it count as a reply to a known message.
        $messages[] = ['role' => 'admin', 'content' => $text, 'author' => $author, 'at' => date('c')] + ($messageId ? ['message_id' => $messageId] : []);

        $this->messages = $messages;
        $this->message_count = count($messages);
        $this->save();
    }
}
