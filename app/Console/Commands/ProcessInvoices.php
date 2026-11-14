<?php

namespace App\Console\Commands;

use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Services\Invoices\InvoiceParser;
use Illuminate\Console\Command;

/** Safety net for the invoice parser: reads photos still waiting, stuck or failed (scheduled every minute). */
class ProcessInvoices extends Command
{
    protected $signature = 'invoices:process {--limit=20 : At most this many photos per run}';

    protected $description = 'Read invoice photos that are still waiting or failed (Admin → Invoice parser)';

    public function handle(InvoiceParser $parser): int
    {
        if (! InvoiceParserSetting::current()->enabled) {
            return self::SUCCESS;
        }

        $done = 0;
        foreach (InvoiceMessage::due()->orderBy('id')->limit((int) $this->option('limit'))->get() as $invoice) {
            $parser->process($invoice);
            $done++;
        }
        if ($done) {
            $this->info("Processed {$done} invoice photo(s).");
        }

        return self::SUCCESS;
    }
}
