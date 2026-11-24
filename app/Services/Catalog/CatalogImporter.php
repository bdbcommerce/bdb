<?php

namespace App\Services\Catalog;

use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\TelegramUser;
use App\Services\AiErrorAlerts;
use App\Services\Telegram\TelegramApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads a full catalog (docs/1c-catalog-http-service.md) from push or pull. A bad product is skipped and listed;
 * the whole load is refused (nothing changes) when it is unreadable, has duplicate SKUs, or is much smaller than
 * the catalog we have — an empty or cut export must never hide the catalog.
 */
class CatalogImporter
{
    /** A load with fewer valid products than this share of the current catalog is refused. */
    public const MIN_RATIO = 0.5;

    /** The ratio check starts once the catalog has this many products (the first loads are small on purpose). */
    public const RATIO_FROM = 10;

    public const MAX_PRODUCTS = 20_000;

    public const MAX_CLIENTS = 10_000;

    public const MAX_BYTES = 20 * 1024 * 1024;

    public const ALERT_MINUTES = 60;

    public function __construct(private TelegramApi $telegram, private AiErrorAlerts $alerts) {}

    /**
     * @return array{load: CatalogLoad, rejected: list<array{sku?: string|null, client?: int, error: string}>}
     */
    public function import(mixed $payload, string $source, string $trigger): array
    {
        $started = microtime(true);
        $settings = CatalogSetting::current();
        $settings->forceFill(['last_attempt_at' => now()])->save();

        $products = is_array($payload) ? ($payload['products'] ?? null) : null;
        if (! is_array($products) || ! array_is_list($products)) {
            return $this->stop($source, $trigger, 'failed', 'The data has no "products" list.', $started);
        }
        if ($products === []) {
            return $this->stop($source, $trigger, 'refused', 'The "products" list is empty.', $started);
        }
        if (count($products) > self::MAX_PRODUCTS) {
            return $this->stop($source, $trigger, 'refused', 'More than '.self::MAX_PRODUCTS.' products in one load.', $started, count($products));
        }

        $valid = $rejected = $seen = [];
        foreach ($products as $i => $item) {
            $sku = is_array($item) && is_string($item['sku'] ?? null) ? trim($item['sku']) : null;
            if ($sku !== null && $sku !== '' && isset($seen[mb_strtolower($sku)])) {
                return $this->stop($source, $trigger, 'refused', 'SKU "'.mb_strimwidth($sku, 0, 64).'" appears more than once.', $started, count($products));
            }
            if ($sku !== null && $sku !== '') {
                $seen[mb_strtolower($sku)] = true;
            }

            $error = null;
            $row = is_array($item) ? $this->product($item, $error) : null;
            if ($row === null) {
                $rejected[] = ['sku' => $sku !== '' ? $sku : null, 'error' => $error ?? "product #{$i} is not an object"];

                continue;
            }
            $valid[] = $row;
        }

        $current = CatalogProduct::where('in_feed', true)->count();
        if ($current >= self::RATIO_FROM && count($valid) < $current * self::MIN_RATIO) {
            return $this->stop($source, $trigger, 'refused', 'Only '.count($valid)." valid products, but the catalog has {$current} — "
                .'a load with less than half is refused (an empty or cut export would hide the catalog).', $started, count($products), $rejected);
        }
        if ($valid === []) {
            return $this->stop($source, $trigger, 'refused', 'No valid products.', $started, count($products), $rejected);
        }

        $clients = null;
        if (is_array($payload) && array_key_exists('clients', $payload)) {
            if (! is_array($payload['clients']) || ! array_is_list($payload['clients'])) {
                return $this->stop($source, $trigger, 'failed', '"clients" must be a list.', $started, count($products), $rejected);
            }
            if (count($payload['clients']) > self::MAX_CLIENTS) {
                return $this->stop($source, $trigger, 'refused', 'More than '.self::MAX_CLIENTS.' clients in one load.', $started, count($products), $rejected);
            }
            $clients = $this->clients($payload['clients'], $rejected);
        }

        $before = CatalogProduct::where('in_feed', true)->pluck('sku')->all();
        DB::transaction(function () use ($valid, $clients) {
            CatalogProduct::query()->update(['in_feed' => false]);
            $now = now();
            foreach (array_chunk($valid, 200) as $chunk) {
                CatalogProduct::upsert(
                    array_map(fn ($r) => $r + ['in_feed' => true, 'created_at' => $now, 'updated_at' => $now], $chunk),
                    ['sku'],
                    [...array_keys($chunk[0]), 'in_feed', 'updated_at'],
                );
            }
            if ($clients !== null) {
                CatalogClient::query()->delete();
                foreach (array_chunk(array_values($clients), 200) as $chunk) {
                    CatalogClient::insert(array_map(fn ($c) => $c + ['created_at' => $now, 'updated_at' => $now], $chunk));
                }
            }
        });

        $hidden = count(array_diff($before, array_column($valid, 'sku')));
        $settings->forceFill(['last_success_at' => now(), 'last_error' => null])->save();

        $load = CatalogLoad::create([
            'source' => $source, 'trigger' => $trigger, 'status' => 'ok',
            'received' => count($products), 'accepted' => count($valid), 'rejected_count' => count($rejected), 'hidden' => $hidden,
            'clients' => $clients === null ? null : count($clients),
            'rejected' => $rejected ? array_slice($rejected, 0, CatalogLoad::KEEP_REJECTED) : null,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return ['load' => $load, 'rejected' => $rejected];
    }

    /** Record a load that could not even be read (e.g. 1C unreachable). */
    public function failed(string $source, string $trigger, string $error): array
    {
        CatalogSetting::current()->forceFill(['last_attempt_at' => now()])->save();

        return $this->stop($source, $trigger, 'failed', $error, microtime(true));
    }

    /** Admin alert (Telegram alerts chat), at most once per ALERT_MINUTES per problem. */
    public function alert(string $reason, ?string $details = null): void
    {
        $chatId = $this->alerts->chatId();
        if (! $chatId || ! $this->telegram->configured() || ! Cache::add('catalog-alert:'.md5($reason), true, now()->addMinutes(self::ALERT_MINUTES))) {
            return;
        }

        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = "⚠️ <b>Product catalog</b>\n\n<b>Problem:</b> ".$e($reason)
            .($details ? "\n<b>Details:</b> <code>".$e(mb_strimwidth(AiErrorAlerts::withoutSecrets($details), 0, 600, '…')).'</code>' : '')
            ."\n<b>Time:</b> ".now()->format('d.m.Y H:i')
            ."\n\nAdmin → AI Sales &amp; Support Team → Product catalog. <i>Repeats are muted for ".self::ALERT_MINUTES.' minutes.</i>';

        try {
            $sent = $this->telegram->sendMessage($chatId, $text, ['parse_mode' => 'HTML', 'disable_web_page_preview' => true], timeout: 5);
        } catch (Throwable) {
            $sent = false;
        }
        if (! $sent) {
            Cache::forget('catalog-alert:'.md5($reason));
        }
    }

    private function stop(string $source, string $trigger, string $status, string $error, float $started, int $received = 0, array $rejected = []): array
    {
        CatalogSetting::current()->forceFill(['last_error' => $error])->save();
        $load = CatalogLoad::create([
            'source' => $source, 'trigger' => $trigger, 'status' => $status, 'received' => $received,
            'rejected_count' => count($rejected), 'rejected' => $rejected ? array_slice($rejected, 0, CatalogLoad::KEEP_REJECTED) : null,
            'error' => $error, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        // Pull problems fail the hourly task, whose alert reports them; a push has no task.
        if ($source === 'push') {
            $this->alert('Catalog push '.($status === 'refused' ? 'refused' : 'failed'), $error);
        }

        return ['load' => $load, 'rejected' => $rejected];
    }

    /** A valid product row for the table, or null with $error. */
    private function product(array $p, ?string &$error): ?array
    {
        $str = fn ($v, int $max) => is_string($v) && trim($v) !== '' && mb_strlen(trim($v)) <= $max ? trim($v) : null;
        $int = fn ($v) => is_int($v) || (is_float($v) && floor($v) === $v && abs($v) < PHP_INT_MAX) ? (int) $v : null;
        $fail = function (string $message) use (&$error) {
            $error = $message;

            return null;
        };

        if (! $sku = $str($p['sku'] ?? null, 64)) {
            return $fail('sku is required (text, at most 64 characters)');
        }
        if (! $brand = $str($p['brand'] ?? null, 64)) {
            return $fail('brand is required (text, at most 64 characters)');
        }
        if (! $model = $str($p['model'] ?? null, 100)) {
            return $fail('model is required (text, at most 100 characters)');
        }
        $category = $p['category'] ?? null;
        if (! is_string($category) || ! isset(CatalogProduct::CATEGORIES[$category])) {
            return $fail('category must be one of: '.implode(', ', array_keys(CatalogProduct::CATEGORIES)));
        }

        $name = [];
        foreach (['uz', 'ru', 'en'] as $locale) {
            if ($v = $str($p['name'][$locale] ?? null, 255)) {
                $name[$locale] = $v;
            }
        }
        if ($name === []) {
            return $fail('name needs at least one of uz / ru / en (at most 255 characters)');
        }

        $prices = [];
        foreach (array_keys(CatalogProduct::PRICE_GROUPS) as $group) {
            $value = $p['prices'][$group] ?? null;
            if ($value === null && $group !== 'end_user') {
                continue;
            }
            $price = $int($value);
            if ($price === null || $price < 0) {
                return $fail("prices.{$group} must be a whole number of sum, 0 or more".($group === 'end_user' ? ' (required)' : ' or null'));
            }
            $prices[$group] = $price;
        }

        $status = $p['stock']['status'] ?? null;
        if (! is_string($status) || ! isset(CatalogProduct::STOCK[$status])) {
            return $fail('stock.status must be one of: '.implode(', ', array_keys(CatalogProduct::STOCK)));
        }
        $numbers = [];
        foreach (['stock_qty' => $p['stock']['qty'] ?? null, 'lead_time_days' => $p['lead_time_days'] ?? null, 'warranty_months' => $p['warranty_months'] ?? null] as $key => $value) {
            // A negative free stock (oversold in 1C) counts as 0; other numbers must not be negative.
            $min = $key === 'stock_qty' ? -100_000_000 : 0;
            if ($value !== null && (($n = $int($value)) === null || $n < $min || $n > 100_000_000)) {
                return $fail(($key === 'stock_qty' ? 'stock.qty' : $key).' must be a whole number'.($min === 0 ? ', 0 or more,' : '').' or null');
            }
            $numbers[$key] = $value === null ? null : max(0, $int($value));
        }

        $params = $p['params'] ?? null;
        if ($params !== null) {
            if (! is_array($params) || ($params !== [] && array_is_list($params)) || count($params) > 50) {
                return $fail('params must be an object with at most 50 keys');
            }
            foreach ($params as $key => $value) {
                $ok = is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/', $key)
                    && (is_scalar($value) || $value === null || (is_array($value) && array_is_list($value) && count($value) <= 20
                        && array_filter($value, fn ($v) => ! is_scalar($v) || (is_string($v) && mb_strlen($v) > 100)) === []));
                if (! $ok || (is_string($value) && mb_strlen($value) > 200)) {
                    return $fail("params.{$key}: keys are lowercase latin / digits / _, values a number, text (≤ 200), true/false or a list");
                }
            }
        }

        $list = function ($value, string $field, int $max, int $len) use (&$error) {
            if ($value === null) {
                return [];
            }
            if (! is_array($value) || ! array_is_list($value) || count($value) > $max
                || array_filter($value, fn ($v) => ! is_string($v) || trim($v) === '' || mb_strlen($v) > $len) !== []) {
                $error = "{$field} must be a list of at most {$max} texts";

                return null;
            }

            return array_values(array_unique(array_map('trim', $value)));
        };
        if (($useCases = $list($p['use_cases'] ?? null, 'use_cases', 10, 32)) === null
            || ($accessories = $list($p['accessories'] ?? null, 'accessories', 30, 64)) === null) {
            return null;
        }
        // Only the use cases from the spec are kept (an unknown one is dropped, not an error).
        $useCases = array_values(array_intersect($useCases, CatalogProduct::USE_CASES));

        if (! is_bool($p['active'] ?? null)) {
            return $fail('active must be true or false');
        }

        return [
            'sku' => $sku, 'brand' => $brand, 'model' => $model, 'category' => $category,
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE), 'prices' => json_encode($prices),
            'stock_status' => $status, ...$numbers,
            'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            'use_cases' => $useCases ? json_encode($useCases) : null,
            'accessories' => $accessories ? json_encode($accessories, JSON_UNESCAPED_UNICODE) : null,
            'is_active' => $p['active'],
        ];
    }

    /** Client rows keyed by phone (a phone listed twice keeps the higher group). Bad entries go to $rejected. */
    private function clients(array $list, array &$rejected): array
    {
        $rank = array_flip(array_keys(CatalogProduct::PRICE_GROUPS));
        $rows = [];
        foreach ($list as $i => $c) {
            $group = is_array($c) ? ($c['price_group'] ?? null) : null;
            $company = is_array($c) && is_string($c['company'] ?? null) ? trim($c['company']) : '';
            $inn = is_array($c) && is_scalar($c['inn'] ?? null) ? preg_replace('/\D/', '', (string) $c['inn']) : '';
            $phones = is_array($c) && is_array($c['phones'] ?? null) ? $c['phones'] : [];

            $error = match (true) {
                ! in_array($group, ['partner', 'dealer'], true) => 'price_group must be partner or dealer',
                $company === '' || mb_strlen($company) > 200 => 'company is required (at most 200 characters)',
                $phones === [] || count($phones) > 50 => 'phones must list 1–50 numbers',
                default => null,
            };
            if ($error) {
                $rejected[] = ['client' => $i, 'error' => $error];

                continue;
            }

            foreach ($phones as $raw) {
                $phone = is_string($raw) ? TelegramUser::normalizePhone($raw) : null;
                if (! $phone) {
                    $rejected[] = ['client' => $i, 'error' => 'phone "'.mb_strimwidth(is_scalar($raw) ? (string) $raw : '?', 0, 30).'" is not a phone number'];

                    continue;
                }
                if (! isset($rows[$phone]) || $rank[$group] > $rank[$rows[$phone]['price_group']]) {
                    $rows[$phone] = ['phone' => $phone, 'price_group' => $group, 'company' => $company, 'inn' => $inn !== '' ? mb_substr($inn, 0, 20) : null];
                }
            }
        }

        return $rows;
    }
}
