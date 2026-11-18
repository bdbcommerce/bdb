<?php

namespace App\Models;

use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of a scheduled task (by the cron scheduler or "Run now" in Admin → Scheduled tasks). */
class ScheduledTaskRun extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 14;

    public $timestamps = false;

    protected $fillable = ['task', 'trigger', 'status', 'exit_code', 'started_at', 'finished_at', 'duration_ms', 'output', 'user_id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'exit_code' => 'integer', 'duration_ms' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** running | success | failed | stuck (still "running" long after it should have ended: killed or crashed). */
    public function state(): string
    {
        if ($this->status === 'running' && $this->started_at->lt(now()->subMinutes(ScheduledTasks::maxMinutes($this->task)))) {
            return 'stuck';
        }

        return $this->status;
    }

    public function duration(): ?string
    {
        if ($this->duration_ms === null) {
            return null;
        }

        $s = $this->duration_ms / 1000;

        return match (true) {
            $s < 1 => $this->duration_ms.' ms',
            $s < 60 => round($s, 1).' s',
            default => intdiv((int) $s, 60).' min '.((int) $s % 60).' s',
        };
    }
}
