<?php

namespace App\Services\Catalog;

use App\Models\CatalogLoad;
use App\Models\CatalogSetting;
use App\Services\AiErrorAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Pull mode: GET the 1C HTTP service (HTTPS + Basic auth) and import what it returns. */
class CatalogPuller
{
    public const TIMEOUT = 60;

    public function __construct(private CatalogImporter $importer) {}

    /**
     * @return array{load: CatalogLoad, rejected: array}|null null when another load is running
     */
    public function pull(string $trigger): ?array
    {
        $lock = Cache::lock('catalog-import', self::TIMEOUT + 120);
        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->run($trigger);
        } finally {
            $lock->release();
        }
    }

    private function run(string $trigger): array
    {
        $settings = CatalogSetting::current();
        $url = (string) $settings->pull_url;
        if (! preg_match('#^https://#i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->importer->failed('pull', $trigger, 'Set the 1C address (https://…) in the catalog settings.');
        }

        $tooBig = false;
        try {
            // No redirects: the 1C login must never be sent to another address. The download stops past MAX_BYTES
            // (also without a Content-Length), so a huge answer never fills the memory.
            $response = Http::withBasicAuth((string) $settings->pull_username, (string) $settings->pull_password)
                ->withoutRedirecting()->acceptJson()->connectTimeout(10)->timeout(self::TIMEOUT)
                ->withOptions(['progress' => function ($total, $downloaded) use (&$tooBig) {
                    if ($total > CatalogImporter::MAX_BYTES || $downloaded > CatalogImporter::MAX_BYTES) {
                        $tooBig = true;
                        throw new \RuntimeException('too big');
                    }
                }])
                ->get($url);
        } catch (Throwable $e) {
            return $this->importer->failed('pull', $trigger, $tooBig ? '1C returned more than 20 MB.' : 'Could not reach 1C: '.$this->clean($e->getMessage(), $settings));
        }

        if (! $response->successful()) {
            $detail = is_string($response->json('error')) ? ': '.mb_strimwidth($response->json('error'), 0, 300, '…') : '';

            return $this->importer->failed('pull', $trigger, "1C answered HTTP {$response->status()}".$this->clean($detail, $settings)
                .($response->status() === 401 ? ' — check the 1C login and password.' : ''));
        }

        $body = $response->body();
        if (strlen($body) > CatalogImporter::MAX_BYTES) {
            return $this->importer->failed('pull', $trigger, '1C returned more than 20 MB.');
        }

        $data = json_decode($body, true);
        if (! is_array($data)) {
            return $this->importer->failed('pull', $trigger, '1C did not return JSON ('.json_last_error_msg().').');
        }

        return $this->importer->import($data, 'pull', $trigger);
    }

    private function clean(string $text, CatalogSetting $settings): string
    {
        // The 1C password is masked whatever its length (withoutSecrets skips short strings); the login too when it is
        // long enough not to mask ordinary words.
        $password = (string) $settings->pull_password;
        if ($password !== '') {
            $text = str_replace($password, '***', $text);
        }

        return AiErrorAlerts::withoutSecrets($text, $settings->pull_username);
    }
}
