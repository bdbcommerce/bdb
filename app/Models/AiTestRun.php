<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of a test case against one prompt version: the conversation it produced and the grade per checklist item. */
class AiTestRun extends Model
{
    /** A case passes at this score (% of checklist items met). */
    public const PASS_SCORE = 80;

    protected $fillable = ['ai_test_case_id', 'ai_employee_id', 'ai_employee_prompt_id', 'prompt_version', 'prompt_hash', 'status', 'score',
        'transcript', 'results', 'error', 'cost_usd', 'started_by', 'finished_at'];

    protected function casts(): array
    {
        return ['transcript' => 'array', 'results' => 'array', 'cost_usd' => 'float', 'finished_at' => 'datetime', 'score' => 'integer'];
    }

    public function testCase(): BelongsTo
    {
        return $this->belongsTo(AiTestCase::class, 'ai_test_case_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiEmployeePrompt::class, 'ai_employee_prompt_id');
    }

    public function passed(): bool
    {
        return $this->status === 'done' && $this->score !== null && $this->score >= self::PASS_SCORE;
    }
}
