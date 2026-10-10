<?php

namespace Database\Seeders;

use App\Models\AiModel;
use Illuminate\Database\Seeder;

/**
 * The free AI model presets (AiModel::PRESETS), added switched off and without keys.
 * In Admin → AI settings each one shows how to get its free key; paste the key, tick
 * Active and save to use it. Safe on production and on re-runs: existing models are kept.
 *
 * php artisan db:seed --class=AiModelSeeder --force
 */
class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AiModel::PRESETS as $preset) {
            AiModel::firstOrCreate(
                ['provider' => $preset['provider'], 'model' => $preset['model']],
                [
                    'name' => $preset['name'],
                    'base_url' => $preset['base_url'],
                    'tasks' => array_keys(AiModel::TASKS),
                    'priority' => $preset['priority'],
                    'is_active' => false,
                ],
            );
        }
    }
}
