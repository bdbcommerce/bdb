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
 */
class ChatConversation extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    /** Conversation types: where the chat took place. */
    public const CHANNELS = ['web' => 'Website', 'telegram' => 'Telegram'];

    protected $fillable = [
        'uuid', 'channel', 'external_id', 'locale', 'page', 'messages', 'message_count', 'unanswered_count', 'chat_lead_id',
    ];

    protected function casts(): array
    {
        return ['messages' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(ChatLead::class, 'chat_lead_id');
    }

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'external_id', 'telegram_id');
    }

    /** Text messages only, without media entries. */
    public function textMessages(): array
    {
        return array_values(array_filter($this->messages ?? [], fn (array $m) => isset($m['content'])));
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

    /** Record media the customer sent (photo, voice, …): only its type and Telegram file ID. */
    public function appendMedia(string $type, ?string $fileId): void
    {
        $messages = $this->messages ?? [];
        $messages[] = ['role' => 'user', 'type' => $type, 'file_id' => $fileId];

        $this->messages = $messages;
        $this->message_count = count($messages);
        $this->save();
    }
}
