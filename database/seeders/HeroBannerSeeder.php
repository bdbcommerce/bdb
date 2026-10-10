<?php

namespace Database\Seeders;

use App\Models\Banner;
use Illuminate\Database\Seeder;

/**
 * Seeds the default home page hero as an editable banner, using the
 * built-in hero copy from lang/{uz,ru,en}/site.php.
 */
class HeroBannerSeeder extends Seeder
{
    private const LOCALES = ['uz', 'ru', 'en'];

    public function run(): void
    {
        $t = fn (string $key) => collect(self::LOCALES)
            ->mapWithKeys(fn (string $lc) => [$lc => trans($key, [], $lc)])
            ->all();

        if (Banner::where('title->en', $t('site.hero.title')['en'])->exists()) {
            $this->command?->info('Default hero banner already exists, skipping.');

            return;
        }

        $points = collect(self::LOCALES)
            ->mapWithKeys(fn (string $lc) => [$lc => implode("\n", (array) trans('site.hero.points', [], $lc))])
            ->all();

        Banner::create([
            'badge' => $t('site.hero.badge'),
            'title' => $t('site.hero.title'),
            'subtitle' => $t('site.hero.lead'),
            'points' => $points,
            'button_text' => $t('site.cta.quote'),
            'button_url' => '/contact',
            'show_ai_button' => true,
            'image' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
