<?php

use App\Models\Banner;
use Illuminate\Database\Migrations\Migration;

/**
 * "Get a quote" is no longer offered: banner buttons that still carry the old default label
 * (exact match per language) become "Contact us". Custom button texts are left alone.
 */
return new class extends Migration
{
    private const OLD = ['en' => 'Get a quote', 'ru' => 'Получить расчёт', 'uz' => 'Narx hisobini olish'];

    private const NEW = ['en' => 'Contact us', 'ru' => 'Связаться с нами', 'uz' => 'Biz bilan bog‘laning'];

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        // Not reversed: "Contact us" labels typed by admins can't be told apart from swapped ones.
    }

    private function swap(array $from, array $to): void
    {
        Banner::query()->each(function (Banner $banner) use ($from, $to) {
            $changed = false;
            foreach ($from as $locale => $label) {
                if (trim((string) $banner->getTranslation('button_text', $locale, false)) === $label) {
                    $banner->setTranslation('button_text', $locale, $to[$locale]);
                    $changed = true;
                }
            }
            if ($changed) {
                $banner->saveQuietly();
            }
        });
    }
};
