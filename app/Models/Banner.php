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
        'show_ai_button', 'image', 'illustration', 'is_active', 'sort_order',
    ];

    public const DEFAULT_ILLUSTRATION = 'scanner';

    /**
     * Built-in animated hero illustrations, shown when a slide has no image.
     * Key => [admin label, admin hint]. Each key has a view in
     * resources/views/site/partials/hero-illustrations/{key}.blade.php.
     */
    public const ILLUSTRATIONS = [
        'scanner' => ['2D code scanning', 'Data Matrix under a scanning laser, GS1 data, Asl Belgi verified'],
        'rfid' => ['RFID reading', 'UHF reader picking up tags, live EPC list'],
        'printer' => ['Label printing', 'Thermal printer feeding a GS1 barcode label'],
        'terminal' => ['Mobile computer', 'Handheld terminal doing a stock count'],
        'aggregation' => ['Aggregation', 'Units packed into a box and a pallet (SSCC)'],
        'fiscal' => ['Fiscal receipt', 'Online cash register receipt with fiscal QR code'],
    ];

    /** A known illustration key; unknown or empty values fall back to the default. */
    public static function illustrationKey(?string $key): string
    {
        return array_key_exists((string) $key, self::ILLUSTRATIONS) ? $key : self::DEFAULT_ILLUSTRATION;
    }

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
