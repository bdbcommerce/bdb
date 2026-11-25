<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question the AI passed to a person (ExpertDesk): an employee with "Answers AI questions" gets it in the bot chat,
 * one at a time. The row is also the training record — the AI's own idea (ai_draft), the expert's verdict and the
 * final answer — and the answer becomes a knowledge draft (SalesKnowledge, inactive until sales approves it).
 * The same topic asked by another customer while open is grouped under the first (parent_id): one answer serves all.
 */
class ExpertQuestion extends Model
{
    /** queued (waiting for a free expert) → asked (with an expert) → answered | unknown; grouped = follows its parent. */
    public const STATUSES = ['queued' => 'Waiting for an expert', 'asked' => 'With an expert', 'answered' => 'Answered', 'unknown' => 'Expert did not know', 'grouped' => 'Grouped with the same question', 'cancelled' => 'Cancelled'];

    public const OPEN = ['queued', 'asked'];

    /** How the expert answered: accepted the AI's idea, fixed it, wrote their own. */
    public const VERDICTS = ['accepted' => 'Accepted the AI idea', 'fixed' => 'Fixed the AI idea', 'written' => 'Wrote the answer', 'unknown' => 'Did not know'];

    /** Transfers to another department before the question goes to the main admin. */
    public const MAX_TRANSFERS = 3;

    protected $fillable = [
        'chat_conversation_id', 'customer_id', 'parent_id', 'client_request_id', 'question', 'customer_text', 'ai_draft', 'topic',
        'knowledge_kind', 'department', 'to_admin', 'language', 'status', 'expert_id', 'message_id', 'asked_at', 'reminded_at',
        'escalated_at', 'fallback_at', 'transfers', 'note', 'pending', 'declined_by', 'answer', 'verdict', 'answered_by', 'answered_at',
        'delivered', 'sales_knowledge_id', 'history',
    ];

    protected function casts(): array
    {
        return ['to_admin' => 'boolean', 'pending' => 'array', 'declined_by' => 'array', 'history' => 'array', 'transfers' => 'integer',
            'asked_at' => 'datetime', 'reminded_at' => 'datetime', 'escalated_at' => 'datetime', 'fallback_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'customer_id');
    }

    public function expert(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'expert_id');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'answered_by');
    }

    public function knowledge(): BelongsTo
    {
        return $this->belongsTo(SalesKnowledge::class, 'sales_knowledge_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /** The row and the customers grouped under it. @return Collection<int, self> */
    public function family(): Collection
    {
        return new Collection([$this, ...$this->children()->where('status', 'grouped')->get()]);
    }

    /** Adds an event to the history (who did what, kept for the training record). */
    public function log(string $event, ?TelegramUser $by = null, array $extra = []): void
    {
        $this->history = [...($this->history ?? []), ['at' => now()->toIso8601String(), 'event' => $event, 'by' => $by?->displayName()] + $extra];
    }

    /** Employees who answer AI questions (and the main admin). @return Collection<int, TelegramUser> */
    public static function experts(): Collection
    {
        return TelegramUser::where(fn ($q) => $q->where('answers_questions', true)->orWhere('is_main_admin', true))
            ->where(fn ($q) => $q->where('contact_type', TelegramUser::TYPE_EMPLOYEE)->orWhere('is_employee', true))
            ->orderBy('id')->get();
    }

    public static function mainAdmin(): ?TelegramUser
    {
        return TelegramUser::where('is_main_admin', true)->where(fn ($q) => $q->where('contact_type', TelegramUser::TYPE_EMPLOYEE)->orWhere('is_employee', true))->orderBy('id')->first();
    }

    /** Departments with at least one expert (their employee roles). @return list<string> */
    public static function departments(): array
    {
        return self::experts()->where('answers_questions', true)->pluck('employee_role')->filter()->unique()->sort()->values()->all();
    }
}
