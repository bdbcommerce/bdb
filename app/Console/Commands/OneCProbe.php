<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls one 1C HTTP method (ONEC_BASE_URL + path, Basic auth with ONEC_LOGIN / ONEC_PASSWORD) and prints the status and
 * the start of the answer, so a new method's response body can be seen before it is mapped. Changes nothing.
 */
class OneCProbe extends Command
{
    protected $signature = 'onec:probe {path : e.g. crm/item} {--method=GET} {--body= : JSON body for POST, e.g. {"stir":"301234567"}} {--chars=3000 : how much of the answer to print}';

    protected $description = 'Call a 1C HTTP method and print the raw answer (to see its response body)';

    public function handle(): int
    {
        $base = (string) config('services.onec.url');
        if ($base === '') {
            $this->error('ONEC_BASE_URL is empty in .env.');

            return self::FAILURE;
        }
        $url = rtrim($base, '/').'/'.ltrim((string) $this->argument('path'), '/');
        if (! str_starts_with(strtolower($url), 'https://')) {
            $this->warn('Plain http: the 1C login and password travel unencrypted. Use https:// for production.');
        }
        $method = strtoupper((string) $this->option('method'));
        $body = $this->option('body') ? json_decode((string) $this->option('body'), true) : null;
        if ($this->option('body') && ! is_array($body)) {
            $this->error('--body is not valid JSON.');

            return self::FAILURE;
        }

        $http = Http::timeout(max(3, min((int) config('services.onec.timeout', 15) * 4, 120)))->acceptJson()->withoutRedirecting();
        if (filled(config('services.onec.login'))) {
            $http = $http->withBasicAuth((string) config('services.onec.login'), (string) config('services.onec.password'));
        }
        $started = microtime(true);
        try {
            $response = $method === 'POST' ? $http->asJson()->post($url, $body ?? []) : $http->get($url, $body ?? []);
        } catch (Throwable $e) {
            $this->error($method.' '.preg_replace('#//[^/@]+@#', '//', $url).' failed: '.str_replace(array_filter([(string) config('services.onec.password'), (string) config('services.onec.login')]), '***', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info($method.' '.preg_replace('#//[^/@]+@#', '//', $url).' → HTTP '.$response->status().' in '.round((microtime(true) - $started) * 1000).' ms, '.strlen($response->body()).' bytes, '.($response->header('Content-Type') ?: 'no content type'));
        $json = $response->json();
        if (is_array($json)) {
            $list = array_is_list($json) ? $json : (collect($json)->first(fn ($v) => is_array($v) && array_is_list($v)) ?? null);
            if (is_array($list)) {
                $this->line('List of '.count($list).' entries; keys of the first: '.implode(', ', array_keys((array) ($list[0] ?? []))));
            } else {
                $this->line('Object with keys: '.implode(', ', array_keys($json)));
            }
            $this->line(mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 0, (int) $this->option('chars')));
        } else {
            $this->line(mb_substr($response->body(), 0, (int) $this->option('chars')));
        }

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
