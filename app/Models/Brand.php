<?php

namespace App\Models;

use App\Models\Concerns\FlushesChatKnowledge;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Brand extends Model
{
    use FlushesChatKnowledge, HasTranslations;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'name', 'logo', 'website', 'shop_url', 'description', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** The online shop filtered by this brand (e.g. mylabel.uz), with UTM tags; null when not set or invalid. */
    public function shopUrl(string $placement = 'brand'): ?string
    {
        return CompanyInfo::withUtm($this->shop_url, $placement);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
