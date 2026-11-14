<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** A visitor followed a brand's website or shop link on the public site (counted by Site\BrandLinkController). */
class LinkClick extends Model
{
    use MassPrunable;

    public const TARGETS = ['website' => 'Official website', 'shop' => 'Buy online'];

    public $timestamps = false;

    protected $fillable = ['brand_id', 'brand_name', 'target', 'locale', 'device', 'visitor_hash', 'clicked_at'];

    protected function casts(): array
    {
        return ['clicked_at' => 'datetime'];
    }

    /** Kept as long as page views. */
    public function prunable(): Builder
    {
        return static::where('clicked_at', '<', now()->subDays(PageView::KEEP_DAYS));
    }
}
