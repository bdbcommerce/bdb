<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected function casts(): array
    {
        return ['messages' => 'array'];
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
     */
    public function appendIncoming(array $entry): void
    {
        $this->messages = [...$this->freshMessages(), ['role' => 'user'] + $entry + (isset($entry['content']) ? ['aside' => true] : [])];
        $this->message_count = count($this->messages);
        $this->save();
    }

    /** The assistant answered the recorded message $messageId (added as a question if it is not there). */
    public function appendReply(?int $messageId, string $question, string $reply, bool $answered): void
    {
        $messages = $this->freshMessages();
        $found = false;
        for ($i = count($messages) - 1; $messageId !== null && $i >= 0; $i--) {
            if (($messages[$i]['message_id'] ?? null) === $messageId && ($messages[$i]['role'] ?? null) === 'user') {
                unset($messages[$i]['aside']);
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

    /** The sender edited message $messageId: replace its content. Returns false when it is not stored here. */
    public function applyEdit(int $messageId, array $content): bool
    {
        $messages = $this->freshMessages();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['message_id'] ?? null) === $messageId) {
                $keep = array_intersect_key($messages[$i], array_flip(['role', 'sender', 'message_id', 'at', 'aside']));
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
