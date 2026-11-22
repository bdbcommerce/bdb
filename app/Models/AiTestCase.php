<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A test case: a scripted customer (turns) and a checklist (criteria) an AI employee's answers are graded on. */
class AiTestCase extends Model
{
    protected $fillable = ['name', 'ai_employee_id', 'via', 'turns', 'criteria', 'is_active'];

    protected function casts(): array
    {
        return ['turns' => 'array', 'criteria' => 'array', 'is_active' => 'boolean'];
    }

    /** Changes when the script or the checklist changes (runs of an older version no longer count). */
    public function hash(): string
    {
        return md5(json_encode([$this->via, array_values($this->turns ?? []), array_values($this->criteria ?? [])]));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiTestRun::class)->orderByDesc('id');
    }

    /** Active cases this employee is tested on: its own and those for every employee. */
    public function scopeFor(Builder $query, AiEmployee $employee): void
    {
        $query->where('is_active', true)->where(fn ($q) => $q->whereNull('ai_employee_id')->orWhere('ai_employee_id', $employee->id));
    }
}
