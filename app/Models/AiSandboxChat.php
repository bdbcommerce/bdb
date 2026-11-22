<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sandbox conversation: an admin plays the customer; the AI employee answers with a real Claude call, nothing reaches Telegram. */
class AiSandboxChat extends Model
{
    use MassPrunable;

    protected $fillable = ['user_id', 'ai_employee_id', 'prompt', 'via', 'messages', 'state'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'state' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }

    public function traces(): HasMany
    {
        return $this->hasMany(AiTrace::class, 'sandbox_chat_id')->orderBy('id');
    }

    public function prunable(): Builder
    {
        return static::where('updated_at', '<', now()->subDays(AiTrace::KEEP_DAYS));
    }
}
