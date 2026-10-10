<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A home page hero slide. With one active banner the hero is static;
 * with several it rotates.
 */
class Banner extends Model
{
    use HasTranslations;

    public array $translatable = ['badge', 'title', 'subtitle', 'points', 'button_text'];

    protected $fillable = [
        'badge', 'title', 'subtitle', 'points', 'button_text', 'button_url',
        'show_ai_button', 'image', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_ai_button' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * Bullet points for a locale. Stored as one point per line.
     *
     * @return array<int, string>
     */
    public function pointList(string $locale): array
    {
        $raw = (string) $this->getTranslation('points', $locale, false);

        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw))));
    }

    /**
     * Button link for a locale. Paths without a locale prefix ("/contact")
     * get one ("/uz/contact"), so a single value works in every language.
     */
    public function buttonHref(string $locale): ?string
    {
        $url = $this->button_url;

        if (! $url || ! str_starts_with($url, '/') || preg_match('#^/(uz|ru|en)(/|$)#', $url)) {
            return $url;
        }

        return '/'.$locale.rtrim($url, '/');
    }
}
