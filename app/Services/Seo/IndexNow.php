<?php

namespace App\Services\Seo;

use App\Models\SeoSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IndexNow: tells Bing (Copilot) and Yandex (Alice) right away which pages changed, instead of
 * waiting for their next crawl. One request to api.indexnow.org reaches every participating engine.
 * The key is proven by /{key}.txt on this site. Never throws.
 */
class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** @param  list<string>  $urls  absolute URLs on this site */
    public function submit(array $urls): ?string
    {
        $settings = SeoSetting::current();
        // The public address from APP_URL (not the address of the current request).
        $base = rtrim((string) config('app.url'), '/');
        $host = parse_url($base, PHP_URL_HOST);
        $urls = array_values(array_unique(array_filter(array_map(SeoFiles::publicUrl(...), $urls), fn ($u) => parse_url($u, PHP_URL_HOST) === $host)));

        if (! $settings->indexnow_enabled || ! $urls) {
            return null;
        }
        if (! str_starts_with($base, 'https://') || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $this->remember($settings, 'Skipped: the site is not on a public https:// address (APP_URL).');
        }

        try {
            $key = $settings->indexNowKey();
            $response = Http::timeout(10)->asJson()->post(self::ENDPOINT, [
                'host' => $host,
                'key' => $key,
                'keyLocation' => $base.'/'.$key.'.txt',
                'urlList' => array_slice($urls, 0, 10000),
            ]);
            $result = $response->successful()
                ? 'Sent '.count($urls).' '.str('page')->plural(count($urls)).' (HTTP '.$response->status().').'
                : 'Refused: HTTP '.$response->status().' — '.mb_substr($response->body(), 0, 120);
        } catch (Throwable $e) {
            Log::warning('IndexNow failed', ['error' => $e->getMessage()]);
            $result = 'Failed: '.mb_substr($e->getMessage(), 0, 150);
        }

        return $this->remember($settings, $result);
    }

    private function remember(SeoSetting $settings, string $result): string
    {
        SeoSetting::whereKey(1)->update(['indexnow_last_at' => now(), 'indexnow_last_result' => mb_substr($result, 0, 255)]);
        SeoSetting::flush();

        return $result;
    }
}
