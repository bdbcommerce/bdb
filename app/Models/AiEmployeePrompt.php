<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One version of an AI employee's role prompt: draft → published (the one in use) → archived. */
class AiEmployeePrompt extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];

    protected $fillable = ['ai_employee_id', 'version', 'prompt', 'note', 'status', 'published_at', 'author'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(AiEmployee::class, 'ai_employee_id');
    }
}
