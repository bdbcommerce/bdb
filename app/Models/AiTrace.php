<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The trace of one AI turn: each step (where: customer | laravel | claude | telegram) with its time from the start
 * (ms) and what was received or sent there — including the full prompt sent to Claude and Claude's raw answer.
 * Recorded for the sandbox always, for real customers only in dev mode. Admin-only; pruned after KEEP_DAYS.
 */
class AiTrace extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 14;

    public const STATUSES = ['sent' => 'Sent', 'silent' => 'Silent', 'dropped' => 'Dropped', 'failed' => 'Failed', 'limit' => 'Limit reached', 'sandbox' => 'Sandbox'];

    public const POINTS = ['customer' => 'Customer', 'laravel' => 'Laravel backend', 'claude' => 'Claude API', 'telegram' => 'Telegram'];

    protected $fillable = ['source', 'chat_conversation_id', 'sandbox_chat_id', 'ai_employee_id', 'prompt_version', 'kind', 'status', 'customer_text',
        'model', 'input_tokens', 'output_tokens', 'cost_usd', 'duration_ms', 'steps'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'cost_usd' => 'float'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
