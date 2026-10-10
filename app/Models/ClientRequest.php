<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer question from the website chat or the Telegram bot. The AI tags it with a
 * short topic (used to group similar questions) and the knowledge entry it answered from.
 * Questions it could not answer stay "open" until an admin answers them with a knowledge entry.
 */
class ClientRequest extends Model
{
    public const STATUSES = [
        'open' => 'Needs an answer',
        'answered' => 'Answered by AI',
        'resolved' => 'Answered by admin',
        'dismissed' => 'Dismissed',
    ];

    /** AI categories that are not requests (greetings, small talk, off-topic) are not saved. */
    public const SKIPPED_CATEGORIES = ['greeting', 'off_topic'];

    protected $fillable = [
        'chat_conversation_id', 'channel', 'external_id', 'locale', 'question', 'ai_reply',
        'status', 'topic', 'category', 'knowledge_entry_id', 'resolved_at', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function knowledgeEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    /** Requests grouped with this one: same AI topic, or just itself when it has none. */
    public function scopeSameGroupAs(Builder $query, self $request): void
    {
        $request->topic
            ? $query->where('topic', $request->topic)
            : $query->whereKey($request->getKey());
    }

    /**
     * Save a question the assistant just handled.
     *
     * @param  array{reply: string, answered: bool, knowledge_id?: int|null, topic?: string|null, category?: string|null}  $result
     */
    public static function record(ChatConversation $conversation, string $question, array $result): ?self
    {
        $category = $result['category'] ?? null;
        if (in_array($category, self::SKIPPED_CATEGORIES, true)) {
            return null;
        }

        $entryId = isset($result['knowledge_id']) && $result['knowledge_id'] > 0
            && KnowledgeEntry::whereKey($result['knowledge_id'])->exists()
            ? (int) $result['knowledge_id'] : null;

        return static::create([
            'chat_conversation_id' => $conversation->id,
            'channel' => $conversation->channel ?? 'web',
            'external_id' => $conversation->external_id,
            'locale' => $conversation->locale,
            'question' => mb_substr($question, 0, 1000),
            'ai_reply' => $result['reply'],
            'status' => $result['answered'] ? 'answered' : 'open',
            'topic' => self::normalizeTopic($result['topic'] ?? null),
            'category' => $category ? mb_substr($category, 0, 32) : null,
            'knowledge_entry_id' => $entryId,
        ]);
    }

    public static function normalizeTopic(?string $topic): ?string
    {
        $topic = trim(mb_strtolower(preg_replace('/\s+/u', ' ', (string) $topic)), " .,!?:;-\"'");

        return $topic === '' ? null : mb_substr($topic, 0, 120);
    }
}
