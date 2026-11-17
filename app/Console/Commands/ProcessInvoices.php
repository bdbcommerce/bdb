<?php

namespace App\Console\Commands;

use App\Models\InvoiceParserSetting;
use App\Services\Invoices\InvoiceParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Invoice parser: reads the stored photos one by one — new, stuck or failed (scheduled every minute, in the background). */
class ProcessInvoices extends Command
{
    protected $signature = 'invoices:process {--limit=20 : At most this many photos per run}';

    protected $description = 'Read invoice photos that are still waiting or failed (Admin → Invoice parser)';

    public function handle(InvoiceParser $parser): int
    {
        // Heartbeat: while the scheduler runs, the webhook leaves the reading to it.
        Cache::put(InvoiceParser::HEARTBEAT, now()->toIso8601String(), now()->addMinutes(3));
        if (! InvoiceParserSetting::current()->enabled) {
            return self::SUCCESS;
        }

        $done = $parser->drain(max(1, (int) $this->option('limit')));
        if ($done) {
            $this->info("Processed {$done} invoice photo(s).");
        }

        return self::SUCCESS;
    }
}
