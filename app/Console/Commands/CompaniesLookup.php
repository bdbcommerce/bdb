<?php

namespace App\Console\Commands;

use App\Services\Companies\CompanyLookup;
use Illuminate\Console\Command;
use Throwable;

/** Tests the company lookups by INN: 1C (ONEC_*), the registry (REGISTRY_*) and 1C purchases. Saves nothing unless --save. */
class CompaniesLookup extends Command
{
    protected $signature = 'companies:lookup {inn : 9 digits} {--purchases : also ask 1C what the company bought} {--from= : purchases since YYYY-MM-DD} {--save : store the company in our base}';

    protected $description = 'Look up a company by INN in 1C and the public registry and print the raw answers';

    public function handle(CompanyLookup $lookup): int
    {
        $inn = preg_replace('/\D/', '', (string) $this->argument('inn'));
        $this->line('1C (read only, the company list): '.(config('services.onec.url') ? rtrim(config('services.onec.url'), '/').'/'.ltrim((string) config('services.onec.companies_path'), '/') : 'not set (ONEC_BASE_URL)'));
        $this->line('Registry: '.(config('services.registry.url') ?: 'not set (REGISTRY_URL)'));
        $failed = false;

        foreach (['1C' => fn () => $lookup->fromOneCList($inn), 'Registry' => fn () => $lookup->fromRegistry($inn)] as $name => $fetch) {
            try {
                $this->newLine();
                $this->info("== {$name}");
                $data = $fetch();
                $this->line($data ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : 'Not found (or not set).');
            } catch (Throwable $e) {
                $failed = true;
                $this->error($e->getMessage());
            }
        }
        if ($this->option('purchases')) {
            try {
                $this->newLine();
                $this->info('== 1C purchases');
                $this->line(json_encode($lookup->purchases($inn, $this->option('from') ?: null), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } catch (Throwable $e) {
                $failed = true;
                $this->error($e->getMessage());
            }
        }
        foreach ($lookup->log as $call) {
            $this->newLine();
            $this->comment("{$call['source']} {$call['url']} → HTTP {$call['status']}");
            $this->line(mb_substr($call['body'], 0, 600).(mb_strlen($call['body']) > 600 ? ' …' : ''));
        }
        if ($this->option('save')) {
            $company = $lookup->find($inn);
            $this->newLine();
            $this->info("Saved: #{$company->id} {$company->name} (source {$company->source})");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
