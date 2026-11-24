<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** One catalog load (push or pull) and its result. Kept KEEP_DAYS, pruned by model:prune. */
class CatalogLoad extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    /** How many rejected products are kept with the load (the API answer lists them all). */
    public const KEEP_REJECTED = 100;

    protected $fillable = ['source', 'trigger', 'status', 'received', 'accepted', 'rejected_count', 'hidden', 'clients', 'rejected', 'error', 'duration_ms'];

    protected function casts(): array
    {
        return ['rejected' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
