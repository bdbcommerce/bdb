<?php

namespace Database\Seeders;

use App\Models\ColorTheme;
use Illuminate\Database\Seeder;

/**
 * The built-in default colour theme (original BDB red) plus the designer presets.
 * Idempotent and safe on production: existing themes and the active choice are kept.
 */
class ColorThemeSeeder extends Seeder
{
    public function run(): void
    {
        ColorTheme::installPresets();
    }
}
