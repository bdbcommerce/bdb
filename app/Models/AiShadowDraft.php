<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply an AI employee in shadow mode wrote: it is not sent until a manager sends it (as is → sent, changed →
 * edited) or discards it. A newer customer turn makes an older pending draft stale.
 */
class AiShadowDraft extends Model
{
    public const STATUSES = ['pending' => 'Waiting', 'sent' => 'Sent as is', 'edited' => 'Edited, then sent', 'discarded' => 'Discarded', 'stale' => 'Out of date'];

    protected $fillable = ['chat_conversation_id', 'ai_employee_id', 'prompt_version', 'draft', 'final', 'status', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }

    /** @return array{decided: int, sent: int, edited: int, discarded: int, rate: int|null} rate = % sent unchanged */
    public static function stats(int $employeeId): array
    {
        $counts = static::where('ai_employee_id', $employeeId)->whereIn('status', ['sent', 'edited', 'discarded'])
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $decided = (int) $counts->sum();

        return ['decided' => $decided, 'sent' => (int) ($counts['sent'] ?? 0), 'edited' => (int) ($counts['edited'] ?? 0),
            'discarded' => (int) ($counts['discarded'] ?? 0), 'rate' => $decided ? (int) round(100 * ($counts['sent'] ?? 0) / $decided) : null];
    }
}
