<?php

namespace App\Services\Companies;

use App\Models\CatalogProduct;
use App\Models\Client;
use App\Models\CompanyItem;
use App\Models\CompanyLink;
use App\Models\OnecItem;
use App\Models\OnecPerson;
use App\Models\TelegramUser;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Companies by INN — read only: our base, then the 1C company list (GET ONEC_COMPANIES_PATH, cached), then the public
 * registry (REGISTRY_URL). Unknown everywhere: a company with the INN only, for a manager to fill in.
 * 1C's clients/newClient CREATES a client in 1C, so it is called only by an admin's button (createInOneC), never by the AI.
 * Also: loading the 1C company and item lists on demand, links to Telegram contacts / groups, and moving sales-plan
 * items a company bought to "usually orders".
 */
class CompanyLookup
{
    /** Remote lookups a day started by customers (INNs in chats / groups); admins are not counted. */
    public const DAILY_REMOTE = 200;

    /** How long the 1C company list is kept for lookups (it is ~500 entries). */
    public const LIST_MINUTES = 60;

    /** Field names accepted in the 1C / registry answers (first one present wins). */
    private const FIELDS = [
        'name' => ['name', 'short_name', 'title', 'company', 'company_name', 'Наименование', 'nomi'],
        'full_name' => ['fullName', 'full_name', 'НаименованиеПолное'],
        'inn' => ['stir', 'inn', 'tin', 'ИНН'],
        'onec_id' => ['id', 'guid'],
        'onec_code' => ['code'],
        'address' => ['address', 'legal_address', 'Адрес', 'ЮридическийАдрес', 'manzil'],
        'director' => ['director', 'head', 'Руководитель', 'Директор', 'rahbar'],
        'city' => ['city', 'region', 'Город', 'Регион', 'shahar'],
        'industry' => ['industry', 'activity', 'okved', 'ВидДеятельности'],
        'price_type' => ['price_type', 'price_group', 'ТипЦен', 'ВидЦен'],
    ];

    /** 1C price types in other words → our groups. */
    private const PRICE_WORDS = ['dealer' => 'dealer', 'дилер' => 'dealer', 'diler' => 'dealer', 'partner' => 'partner', 'партнер' => 'partner', 'партнёр' => 'partner',
        'hamkor' => 'partner', 'end_user' => 'end_user', 'retail' => 'end_user', 'розница' => 'end_user', 'розничная' => 'end_user', 'chakana' => 'end_user'];

    /** The last remote answers (for the admin test and the artisan command). */
    public array $log = [];

    /**
     * The company with this INN: ours, else from the 1C list / the registry (created here, not in 1C), else a new one
     * with the INN only. $byCustomer: started by an INN a customer sent (counted against DAILY_REMOTE).
     */
    public function find(string $inn, bool $byCustomer = false): Client
    {
        $inn = (string) Client::cleanInn($inn);
        if (! Client::isInn($inn)) {
            throw new RuntimeException('An INN is 9 digits (a sole trader: 12 or 14).');
        }
        if ($client = Client::where('inn', $inn)->first()) {
            return $client;
        }
        if ($byCustomer && ! $this->allowRemote()) {
            return $this->store($inn, 'local', ['name' => 'INN '.$inn]);
        }
        foreach (['1c' => fn () => $this->fromOneCList($inn), 'registry' => fn () => $this->fromRegistry($inn)] as $source => $fetch) {
            $data = rescue($fetch, null, false);
            if ($data && filled($data['name'] ?? null)) {
                return $this->store($inn, $source, $data);
            }
        }

        return $this->store($inn, 'local', ['name' => 'INN '.$inn]);
    }

    /** "Update from 1C": the 1C list (fresh) and the registry again, for a company we have. */
    public function refresh(Client $client): Client
    {
        if (! Client::isInn($client->inn)) {
            throw new RuntimeException('This company has no 9-digit INN.');
        }
        Cache::forget('onec:companies');
        foreach (['1c' => fn () => $this->fromOneCList($client->inn), 'registry' => fn () => $this->fromRegistry($client->inn)] as $source => $fetch) {
            if (($data = $fetch()) && filled($data['name'] ?? null)) {
                $client->update($this->fields($data, $source, $client) + ['source' => $source, 'lookup_data' => $data, 'looked_up_at' => now()]);

                return $client;
            }
        }
        throw new RuntimeException('Neither 1C nor the registry knows INN '.$client->inn.'.');
    }

    /** Admin button only: creates the client in 1C (clients/newClient, POST {"stir": inn}) and keeps its 1C code. */
    public function createInOneC(Client $client): Client
    {
        $c = config('services.onec');
        if (blank($c['url'] ?? null) || blank($c['company_path'] ?? null)) {
            throw new RuntimeException('1C is not set (ONEC_BASE_URL).');
        }
        if (! Client::isInn($client->inn)) {
            throw new RuntimeException('This company has no 9-digit INN.');
        }
        $post = ($c['company_method'] ?? 'POST') === 'POST' ? [(string) ($c['company_field'] ?? 'stir') => $client->inn] : null;
        $body = $this->get('1C newClient', $this->url($c['url'], $c['company_path'], $client->inn), $this->oneCAuth(), (int) ($c['timeout'] ?? 15), $post, notFound: [400, 404]);
        $data = $body === null ? null : $this->mapped($body);
        if (! $data || blank($data['name'] ?? null)) {
            throw new RuntimeException('1C did not create the client (it does not know INN '.$client->inn.').');
        }
        Cache::forget('onec:companies');
        $client->update($this->fields($data, '1c', $client) + ['source' => '1c', 'lookup_data' => $data, 'looked_up_at' => now()]);

        return $client;
    }

    /** @return array<string, mixed>|null the company from the 1C list (our field names) */
    public function fromOneCList(string $inn): ?array
    {
        foreach ($this->oneCCompanies() as $row) {
            if (($row['inn'] ?? null) === $inn) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> the 1C company list, mapped (cached LIST_MINUTES) */
    public function oneCCompanies(): array
    {
        $c = config('services.onec');
        if (blank($c['url'] ?? null) || blank($c['companies_path'] ?? null)) {
            return [];
        }

        return Cache::remember('onec:companies', now()->addMinutes(self::LIST_MINUTES), function () use ($c) {
            $body = $this->get('1C companies', $this->url($c['url'], $c['companies_path'], ''), $this->oneCAuth(), max(30, (int) ($c['timeout'] ?? 15)));

            return array_values(array_filter(array_map(fn ($row) => is_array($row) ? $this->mappedRow($row) : null, $this->rows($body))));
        });
    }

    /**
     * Admin "Load companies from 1C": every 1C company with a 9-digit STIR is added or updated (name, full name,
     * address, 1C id and code; our price type, lists and notes stay). Private persons (no STIR) are counted only.
     *
     * @return array{added: int, updated: int, private: int, other: int}
     */
    public function importCompanies(): array
    {
        Cache::forget('onec:companies');
        $counts = ['added' => 0, 'updated' => 0, 'private' => 0, 'other' => 0];
        foreach ($this->oneCCompanies() as $row) {
            $inn = (string) ($row['inn'] ?? '');
            if ($inn === '') {
                // A private person: kept with their phone, to show on the Telegram contact with the same phone.
                if (filled($row['onec_id'] ?? null) && filled($row['name'] ?? null)) {
                    OnecPerson::updateOrCreate(['onec_id' => mb_substr((string) $row['onec_id'], 0, 40)], [
                        'code' => is_numeric($row['onec_code'] ?? null) ? (int) $row['onec_code'] : null, 'name' => mb_substr(self::oneLine((string) $row['name']), 0, 150),
                        'phone' => $row['phone'] ?? null, 'address' => filled($row['address'] ?? null) ? mb_substr(self::oneLine((string) $row['address']), 0, 300) : null,
                    ]);
                }
                $counts['private']++;

                continue;
            }
            if (! Client::isInn($inn)) {
                $counts['other']++; // a number of another length

                continue;
            }
            $client = Client::where('inn', $inn)->first() ?? (filled($row['onec_id'] ?? null) ? Client::where('onec_id', $row['onec_id'])->first() : null);
            if ($client) {
                $client->update($this->fields($row, '1c', $client) + ['inn' => $inn, 'source' => '1c', 'lookup_data' => $row, 'looked_up_at' => now()]);
                $counts['updated']++;
            } else {
                $this->store($inn, '1c', $row);
                $counts['added']++;
            }
        }

        return $counts;
    }

    /**
     * Admin "Load items from 1C" (crm/item): added or updated by their 1C id.
     *
     * @return array{saved: int, skipped: int}
     */
    public function importItems(): array
    {
        $c = config('services.onec');
        if (blank($c['url'] ?? null) || blank($c['items_path'] ?? null)) {
            throw new RuntimeException('1C is not set (ONEC_BASE_URL / ONEC_ITEMS_PATH).');
        }
        $body = $this->get('1C items', $this->url($c['url'], $c['items_path'], ''), $this->oneCAuth(), max(30, (int) ($c['timeout'] ?? 15)));
        $cut = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(self::oneLine((string) $v), 0, $max) : null;
        $counts = ['saved' => 0, 'skipped' => 0];
        foreach (array_slice($this->rows($body), 0, 20000) as $row) {
            if (! is_array($row) || ! is_scalar($row['id'] ?? null) || ! is_numeric($row['code'] ?? null) || blank($row['name'] ?? null)) {
                $counts['skipped']++;

                continue;
            }
            OnecItem::updateOrCreate(['onec_id' => mb_substr((string) $row['id'], 0, 40)], [
                'code' => (int) $row['code'], 'name' => $cut($row['name'], 200), 'full_name' => $cut($row['acc_name'] ?? null, 300), 'short_name' => $cut($row['short_name'] ?? null, 200),
                'mxik' => $cut($row['mxik'] ?? null, 20), 'barcode' => $cut($row['barcode'] ?? null, 64), 'unit' => $cut($row['unit'] ?? null, 40),
                'category_id' => is_numeric($row['category_id'] ?? null) ? (int) $row['category_id'] : null,
                'company_onec_id' => $cut($row['company_id'] ?? null, 40), 'description' => $cut($row['description'] ?? null, 500),
            ]);
            $counts['saved']++;
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function fromRegistry(string $inn): ?array
    {
        $c = config('services.registry');
        if (blank($c['url'] ?? null)) {
            return null;
        }
        $body = $this->get('registry', str_replace('{inn}', $inn, $c['url']), fn ($http) => filled($c['token'] ?? null) ? $http->withToken($c['token']) : $http, (int) ($c['timeout'] ?? 15));

        return $body === null ? null : $this->mapped($body);
    }

    /**
     * What the company bought, from 1C (ONEC_PURCHASES_PATH, ?from=YYYY-MM-DD).
     *
     * @return list<array{sku: string, qty: int|null, date: string|null}>
     */
    public function purchases(string $inn, ?string $from = null): array
    {
        $c = config('services.onec');
        if (blank($c['url'] ?? null) || blank($c['purchases_path'] ?? null) || ! Client::isInn($inn)) {
            return [];
        }
        $url = $this->url($c['url'], $c['purchases_path'], $inn).($from ? (str_contains($c['purchases_path'], '?') ? '&' : '?').'from='.urlencode($from) : '');
        $body = $this->get('1C purchases', $url, $this->oneCAuth(), (int) ($c['timeout'] ?? 15));

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row)) {
                return null;
            }
            $sku = $row['sku'] ?? $row['SKU'] ?? $row['article'] ?? $row['Артикул'] ?? $row['code'] ?? null;

            return is_scalar($sku) && trim((string) $sku) !== '' ? ['sku' => mb_substr(trim((string) $sku), 0, 64), 'qty' => is_numeric($row['qty'] ?? $row['quantity'] ?? null) ? (int) ($row['qty'] ?? $row['quantity']) : null,
                'date' => is_scalar($row['date'] ?? null) ? (string) $row['date'] : null] : null;
        }, array_slice($this->rows($body, ['purchases', 'items', 'data']), 0, 2000))));
    }

    /** Links a company to a Telegram contact or a group (a manager's link is never downgraded to an AI one). */
    public function link(Client $client, ?TelegramUser $user, ?string $groupChatId, string $by, ?string $name = null): CompanyLink
    {
        $key = $user ? ['client_id' => $client->id, 'telegram_user_id' => $user->id] : ['client_id' => $client->id, 'group_chat_id' => (string) $groupChatId];
        $link = CompanyLink::firstOrNew($key);
        if (! $link->exists || $by === 'manager') {
            $link->fill(['linked_by' => $by, 'linked_by_name' => $name])->save();
        }

        return $link;
    }

    /**
     * Sales-plan items covered by what the company bought move to "usually orders".
     *
     * @param  list<array{product?: string, sku?: string}>  $bought  request items (free text) or 1C rows (sku / 1C item code)
     * @return list<string> the moved entries' labels
     */
    public function moveBought(Client $client, array $bought, string $by): array
    {
        $moved = [];
        foreach ($client->items()->where('list', 'plan')->get() as $item) {
            foreach ($bought as $row) {
                $product = filled($row['sku'] ?? null) ? CatalogProduct::where('sku', $row['sku'])->first() : null;
                if ($item->covers($product, (string) ($row['product'] ?? $row['sku'] ?? ''))) {
                    DB::transaction(function () use ($client, $item, $by) {
                        CompanyItem::where(['client_id' => $client->id, 'list' => 'usual', 'kind' => $item->kind, 'value' => $item->value])->delete();
                        $item->update(['list' => 'usual', 'moved_at' => now(), 'moved_by' => mb_substr($by, 0, 120)]);
                    });
                    $moved[] = $item->label();
                    break;
                }
            }
        }

        return $moved;
    }

    /** Line breaks and control characters out (company data goes into prompts and Telegram messages). */
    public static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $text));
    }

    private function allowRemote(): bool
    {
        $key = 'company-lookups:'.now()->toDateString();
        Cache::add($key, 0, now()->addDay());

        return Cache::increment($key) <= self::DAILY_REMOTE;
    }

    private function store(string $inn, string $source, array $data): Client
    {
        try {
            return Client::create($this->fields($data, $source) + ['inn' => $inn, 'source' => $source, 'status' => 'prospect',
                'lookup_data' => $source === 'local' ? null : $data, 'looked_up_at' => $source === 'local' ? null : now()]);
        } catch (Throwable $e) {
            // Another request created it a moment ago (INN / 1C id are unique).
            return Client::where('inn', $inn)->first() ?? throw $e;
        }
    }

    /** Our columns from a mapped answer. A price type is taken only from 1C, never from the public registry. */
    private function fields(array $data, string $source, ?Client $current = null): array
    {
        $cut = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(self::oneLine((string) $v), 0, $max) : null;
        $onecId = $source === '1c' ? $cut($data['onec_id'] ?? null, 40) : null;
        if ($onecId && Client::where('onec_id', $onecId)->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            $onecId = null; // that 1C card is already another company here
        }

        return array_filter([
            'name' => $cut($data['name'] ?? null, 150),
            'full_name' => $cut($data['full_name'] ?? null, 300),
            'onec_id' => $onecId,
            'onec_code' => $source === '1c' && is_numeric($data['onec_code'] ?? null) ? (int) $data['onec_code'] : null,
            'contract' => $source === '1c' && in_array($data['contract'] ?? null, ['annual', 'per_deal'], true) ? $data['contract'] : null,
            'address' => $cut($data['address'] ?? null, 300),
            'director' => $cut($data['director'] ?? null, 150),
            'city' => $current?->city ?? $cut($data['city'] ?? null, 80),
            'industry' => $current?->industry ?? $cut($data['industry'] ?? null, 100),
            'price_type' => $source === '1c' ? $this->priceType($data['price_type'] ?? null) : null,
        ], fn ($v) => $v !== null);
    }

    private function priceType(mixed $value): ?string
    {
        return is_scalar($value) ? (self::PRICE_WORDS[mb_strtolower(trim((string) $value))] ?? null) : null;
    }

    /** The rows of a list answer: {"count": n, "data": [...]} or a bare list. */
    private function rows(mixed $body, array $wraps = ['data', 'items', 'companies', 'result']): array
    {
        if (is_array($body) && ! array_is_list($body)) {
            foreach ($wraps as $wrap) {
                if (is_array($body[$wrap] ?? null)) {
                    return array_is_list($body[$wrap]) ? $body[$wrap] : [$body[$wrap]];
                }
            }

            return [];
        }

        return is_array($body) ? $body : [];
    }

    /** One company: the answer itself, or wrapped (data / company / result), or a list with it first. */
    private function mapped(mixed $body): ?array
    {
        $rows = is_array($body) && (array_is_list($body) || is_array($body['data'] ?? $body['company'] ?? $body['result'] ?? null)) ? $this->rows($body, ['data', 'company', 'result', 'item']) : [$body];
        $first = $rows[0] ?? null;

        return is_array($first) ? $this->mappedRow($first) : null;
    }

    private function mappedRow(array $row): ?array
    {
        $out = [];
        foreach (self::FIELDS as $field => $names) {
            foreach ($names as $name) {
                if (isset($row[$name]) && is_scalar($row[$name]) && trim((string) $row[$name]) !== '') {
                    $out[$field] = $field === 'inn' ? (string) Client::cleanInn((string) $row[$name]) : trim((string) $row[$name]);
                    break;
                }
            }
        }
        if (! isset($out['name']) && isset($out['full_name'])) {
            $out['name'] = $out['full_name']; // a registry answer may carry the official name only
        }
        if (isset($row['one_time']) && is_bool($row['one_time'])) {
            $out['contract'] = $row['one_time'] ? 'per_deal' : 'annual';
        }
        if (isset($row['mob']) && is_scalar($row['mob']) && ($phone = TelegramUser::normalizePhone((string) $row['mob']))) {
            $out['phone'] = $phone;
        }

        return $out ?: null;
    }

    private function oneCAuth(): callable
    {
        $c = config('services.onec');

        return fn ($http) => filled($c['login'] ?? null) ? $http->withBasicAuth((string) $c['login'], (string) $c['password']) : $http;
    }

    private function url(string $base, string $path, string $inn): string
    {
        return rtrim($base, '/').'/'.ltrim(str_replace('{inn}', $inn, $path), '/');
    }

    /**
     * GET (or POST with a JSON body) → JSON; the $notFound statuses = unknown (null). HTTPS only (plain http only
     * locally / in tests), no redirects; the login never appears in errors.
     */
    private function get(string $what, string $url, callable $auth, int $timeout, ?array $post = null, array $notFound = [404]): mixed
    {
        if (! str_starts_with(strtolower($url), 'https://') && ! (app()->isLocal() || app()->runningUnitTests())) {
            throw new RuntimeException("{$what}: the URL must start with https://");
        }
        $http = $auth(Http::timeout(max(3, min($timeout, 120)))->acceptJson()->withoutRedirecting());
        /** @var Response $response */
        $response = $post === null ? $http->get($url) : $http->asJson()->post($url, $post);
        $this->log[] = ['source' => $what, 'url' => preg_replace('#//[^/@]+@#', '//', $url), 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 3000)];
        // 1C answers an unknown INN with 400 {"count":0,"data":"No Data in Hippo"}.
        if (in_array($response->status(), $notFound, true) || ($response->status() === 400 && (int) ($response->json('count') ?? -1) === 0)) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException("{$what}: HTTP {$response->status()}");
        }

        return $response->json();
    }
}
