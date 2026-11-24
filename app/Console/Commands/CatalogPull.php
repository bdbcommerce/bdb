<?php

namespace App\Console\Commands;

use App\Models\CatalogSetting;
use App\Services\Catalog\CatalogPuller;
use Illuminate\Console\Command;

/**
 * Hourly: in pull mode, loads the catalog from the 1C HTTP service; in both modes, fails when the catalog is
 * older than its max age (the AI then quotes no prices or stock). Failures are reported by the scheduled-task alert.
 */
class CatalogPull extends Command
{
    protected $signature = 'catalog:pull {--manual : started by hand}';

    protected $description = 'Load the product catalog from 1C (pull mode) and check that it is fresh';

    public function handle(CatalogPuller $puller): int
    {
        $settings = CatalogSetting::current();
        $failed = false;

        if ($settings->source === 'pull') {
            $result = $puller->pull($this->option('manual') ? 'manual' : 'schedule');
            if ($result === null) {
                $this->warn('Another catalog load is running.');
            } else {
                $load = $result['load'];
                $line = "Pull {$load->status}: {$load->received} received, {$load->accepted} accepted, {$load->rejected_count} rejected, {$load->hidden} hidden"
                    .($load->clients !== null ? ", {$load->clients} client phones" : '');
                $load->status === 'ok' ? $this->info($line) : $this->error($line.'. '.$load->error);
                $failed = $load->status !== 'ok';
            }
        } else {
            $this->line('Push mode: 1C sends the catalog to /api/catalog.');
        }

        $settings->refresh();
        if ($settings->last_success_at === null) {
            $this->line('No catalog loaded yet.');
        } elseif (! $settings->isFresh()) {
            $this->error("The catalog is stale: last good load {$settings->last_success_at->format('d.m.Y H:i')}, max age {$settings->max_age_hours} h. The AI quotes no prices or stock.");
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
