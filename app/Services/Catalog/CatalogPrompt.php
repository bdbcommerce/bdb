<?php

namespace App\Services\Catalog;

use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;

/**
 * The PRODUCT CATALOG section of the sales assistant's prompt: the products that match the conversation, with
 * only the prices the customer's group may see (CatalogClient::priceGroupFor — a verified phone), a cheaper and a
 * better model of the same category and the accessories. A stale catalog (CatalogSetting::isFresh) gives models and
 * parameters only: no price, stock or delivery time reaches Claude.
 */
class CatalogPrompt
{
    /** Products named in the conversation (SKU / model). */
    public const MAX_DIRECT = 10;

    /** Products of a category the customer asks about (spread over the price range). */
    public const PER_CATEGORY = 8;

    /** All products in the section. */
    public const MAX_PRODUCTS = 30;

    /**
     * Words that point at a category, in uz (Latin and Cyrillic), ru and en; lowercase stems found anywhere in
     * the text, "=" = a whole word only.
     */
    public const CATEGORY_WORDS = [
        'label_printer' => ['принтер этикет', 'принтер для этикет', 'этикеточн', 'термопринтер', 'термотрансфер', 'label printer', 'etiketka printer', 'printer etiket', 'etiketka chiqar', 'этикетка принтер', 'этикетка чиқар', 'штрихкод принтер', 'штрих-код принтер', 'принтер штрих', 'barcode printer', 'принтер для маркир'],
        'receipt_printer' => ['чековый принтер', 'чекового принтер', 'чековым принтер', 'принтер чек', 'чек принтер', 'receipt printer', 'chek printer', 'chek chiqar', 'чек чиқар'],
        'scanner' => ['сканер', 'skaner', 'scanner', 'сканир'],
        'tsd' => ['=тсд', 'терминал сбора', 'data collection', '=tsd', '=pda', 'mobile computer', "ma'lumot yig", 'маълумот йиғ'],
        'label' => ['этикет', 'etiket', '=label', '=labels', 'наклейк', 'yorliq', 'ёрлиқ', 'стикер', 'sticker'],
        'ribbon' => ['риббон', 'рибон', 'ribbon', 'красящ'],
        'receipt_paper' => ['чековая лент', 'чековой лент', 'чековую лент', 'лента для чек', 'ленты для чек', 'ленту для чек', 'кассовая лент', 'кассовой лент', 'кассовую лент', 'термолент', 'термобумаг', 'thermal paper', 'chek qog', 'chek lent', 'kassa lent', 'kassa qog', 'чек лент', 'чек қоғоз', 'касса лент', 'receipt paper', 'till roll'],
        'scale' => ['весы', 'весов', 'весах', 'весам', 'tarozi', 'тарози', '=scale', '=scales'],
        'pos' => ['моноблок', '=pos', 'pos термин', 'pos-термин', 'pos terminal', 'ноутбук', 'noutbuk', 'laptop', 'сенсорный монитор'],
        'rfid' => ['rfid', 'рфид'],
        'software' => ['программ', 'dastur', 'дастур', 'software'],
        'service' => ['ремонт', 'настройк', 'установк', "ta'mir", 'tamir', 'таъмир', 'тамир', 'sozla', 'созла', '=service', 'сервис'],
    ];

    /** "Printer" alone: both kinds, when the text names no specific printer. */
    public const PRINTER_WORDS = ['принтер', 'printer', 'принтэр'];

    /**
     * @param  string  $text  what the customer wrote (and the items collected so far)
     * @return array{text: string, info: array} the prompt section ('' when there is no catalog) and what was chosen (for the trace)
     */
    public static function section(?TelegramUser $user, string $text): array
    {
        $settings = CatalogSetting::current();
        $index = self::index($settings);
        if (! $index) {
            return ['text' => '', 'info' => ['catalog' => 'empty — the playbook rules on prices apply']];
        }

        $fresh = $settings->isFresh();
        $group = CatalogClient::priceGroupFor($user);
        $ids = self::choose($index, $text);
        $products = $ids ? CatalogProduct::available()->whereIn('id', $ids)->get()->keyBy('id') : collect();

        // Cheaper / better / accessories point only at products that are listed too (their price is there).
        $listed = $products->mapWithKeys(fn ($p) => [self::key($p->sku) => $p->sku])->all();
        $lines = [];
        foreach ($ids as $id) {
            if ($p = $products->get($id)) {
                $lines[] = json_encode(self::line($p, $group, $fresh, $index, $listed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }

        $counts = [];
        foreach ($index as $row) {
            $counts[$row['category']] = ($counts[$row['category']] ?? 0) + 1;
        }
        $categories = implode(', ', array_map(fn ($c, $n) => CatalogProduct::categoryLabel($c)." ({$n})", array_keys($counts), $counts));
        $loaded = $settings->last_success_at?->setTimezone('Asia/Tashkent')->format('d.m.Y H:i') ?? '—';

        $rules = $fresh ? self::rules($group, $loaded) : self::staleRules($loaded);
        $body = $lines
            ? "Products matching this conversation (one JSON object per line):\n".implode("\n", $lines)
            : 'No product of the catalog matches this conversation yet. When the customer names a model or a kind of product, the matching products appear here in the next turn.';

        return [
            'text' => "\n\n# PRODUCT CATALOG (from 1C, loaded {$loaded} Tashkent)\n{$rules}\nThe catalog has: {$categories}.\n{$body}",
            'info' => ['price_group' => $group, 'fresh' => $fresh, 'loaded' => $loaded, 'products' => array_values(array_filter(array_map(fn ($id) => $products->get($id)?->sku, $ids)))],
        ];
    }

    private static function rules(string $group, string $loaded): string
    {
        $who = match ($group) {
            'partner' => 'a PARTNER (verified phone): "price" is their own price; "dealer_price" and "retail_price" are the dealer and end-user prices, which they may also see',
            'dealer' => 'a DEALER (verified phone): "price" is their own price; "retail_price" is the end-user price, which they may also see',
            default => 'an end user: "price" is the only price they may hear',
        };

        return <<<TXT
This section replaces the SALES PLAYBOOK rule that forbids prices and stock: you MAY quote the prices and stock below, exactly as listed. STAFF RULES and platform rules still apply.
- Use only the prices in this section now. A price written earlier in the conversation may be out of date or for another price group: never repeat it, quote the current one from here.
- This customer is {$who}. Prices are in UZS (so'm) including VAT — say so. Quote a price exactly as written, never round it, never compute a discount, never mention a price group other than the ones given here.
- A product without "price" has no price in the catalog: say the manager will give it.
- A price is named only when the customer's phone is known (Known contacts, or written in this conversation). If it is not, first ask for the phone in one short sentence, then give the price.
- With a product you offer, also name one cheaper and one better model of the list ("cheaper" / "better" give their sku) when they exist, and one fitting accessory ("accessories"), each with its price — briefly, as advice.
- Stock: in_stock = available; low = only a few left; to_order = to order, delivery in lead_time_days days (if given); out = not available now — offer the alternatives. "qty" is the free stock as a range (e.g. "6-10", "50+"); never tell it. When the customer asks for a quantity up to the lower end of the range, it is available; a larger one: the manager confirms.
- Never grant or promise a discount or special terms. If asked: a long-term partnership, a larger quantity or more products in one invoice can help — the manager decides; then hand the request to the manager.
- Only products listed here exist for you. A product the customer names that is not listed: do not say we do not have it — ask for the exact model, or say the manager will check.
- In summary, write every price you quoted with the sku and "catalog {$loaded}".
TXT;
    }

    private static function staleRules(string $loaded): string
    {
        return <<<TXT
The catalog is OUT OF DATE (last loaded {$loaded}): use it only to know which models exist and their parameters. Never give any price, stock, availability or delivery time from it — say the manager will confirm the price and availability.
- Only products listed here exist for you. A product the customer names that is not listed: do not say we do not have it — ask for the exact model, or say the manager will check.
TXT;
    }

    /** One product for the prompt: only what the group may see; no price or stock when stale. */
    private static function line(CatalogProduct $p, string $group, bool $fresh, array $index, array $listed): array
    {
        $line = ['sku' => $p->sku, 'name' => trim($p->brand.' '.$p->model), 'category' => $p->category];
        if ($fresh) {
            // 0 in 1C = no price set: never quoted.
            $prices = array_filter($p->pricesFor($group), fn ($v) => $v > 0);
            $own = $prices ? end($prices) : null; // the highest group's price visible (a missing dealer price → end-user price)
            if ($own !== null) {
                $line['price'] = self::money($own);
                $labels = ['dealer' => 'dealer_price', 'end_user' => 'retail_price'];
                foreach (array_slice(array_reverse($prices, true), 1, null, true) as $g => $value) {
                    $line[$labels[$g]] = self::money($value);
                }
            }
            $line['stock'] = $p->stock_status;
            if ($p->stock_qty !== null) {
                $line['qty'] = self::range($p->stock_qty); // the exact stock never reaches the prompt
            }
            if ($p->lead_time_days !== null) {
                $line['lead_time_days'] = $p->lead_time_days;
            }
        }
        if ($p->warranty_months !== null) {
            $line['warranty_months'] = $p->warranty_months;
        }
        if ($p->params) {
            $line['params'] = $p->params;
        }
        if ($p->use_cases) {
            $line['use_cases'] = $p->use_cases;
        }
        // Accessories as 1C wrote them ("RIB110" for "RIB-110"), shown with the listed product's own SKU.
        $accessories = [];
        foreach ((array) $p->accessories as $sku) {
            if (is_string($sku) && ($own = $listed[self::key($sku)] ?? null) && $own !== $p->sku) {
                $accessories[$own] = true;
            }
        }
        if ($accessories) {
            $line['accessories'] = array_slice(array_keys($accessories), 0, 3);
        }
        if ($row = $index[mb_strtolower($p->sku)] ?? null) {
            $byId = array_column($index, 'sku', 'id');
            [$cheaper, $better] = self::nearestIds($row, $index);
            if ($cheaper && isset($listed[self::key($byId[$cheaper])])) {
                $line['cheaper'] = $byId[$cheaper];
            }
            if ($better && isset($listed[self::key($byId[$better])])) {
                $line['better'] = $byId[$better];
            }
        }

        return $line;
    }

    /** The free stock as a range, so the exact quantity (commercial data) never reaches the prompt. */
    public static function range(int $qty): string
    {
        foreach ([[0, 0], [1, 5], [6, 10], [11, 20], [21, 50]] as [$from, $to]) {
            if ($qty <= $to) {
                return $from === $to ? (string) $from : "{$from}-{$to}";
            }
        }

        return '50+';
    }

    public static function money(int $sum): string
    {
        return number_format($sum, 0, '.', ' ');
    }

    /**
     * Whether the customer wrote this SKU / model ($key: lowercase, no spaces or dashes) — as one word or as up to
     * three words written apart ("zd 421t", "P-1", "Voyager 1450g"), never as a piece of a longer word or across
     * unrelated words. A number (a numeric 1C code) counts only as a whole word: a phone number or a quantity never
     * matches it.
     *
     * @param  array<string, true>  $singles  the words of the text
     * @param  array<string, true>  $grams  the words and the joined runs of 2–3 neighbouring words
     */
    private static function mentioned(string $key, array $singles, array $grams): bool
    {
        if (mb_strlen($key) < 2) {
            return false;
        }

        return preg_match('/^\p{N}+$/u', $key) ? isset($singles[$key]) : isset($grams[$key]);
    }

    /**
     * The words of the text, lowercase; typographic apostrophes as "'" (o‘zbek, ma’lumot).
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function words(string $text): array
    {
        $text = str_replace(['’', '‘', 'ʻ', 'ʼ', '`', '´'], "'", mb_strtolower(mb_substr($text, -2000)));

        return [$text, array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [], fn ($w) => $w !== ''))];
    }

    /**
     * The product IDs for the section, best first: products the customer named (SKU / model), their accessories and
     * cheaper / better neighbours, then the categories the customer asks about (preferring a brand they named) and
     * the categories of the named products.
     *
     * @return list<int>
     */
    public static function choose(array $index, string $text): array
    {
        [$text, $tokens] = self::words($text);
        $singles = array_fill_keys($tokens, true);
        $grams = $singles;
        $pairs = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($i + 1 < $n) {
                $pairs[$tokens[$i].$tokens[$i + 1]] = true;
                $grams[$tokens[$i].$tokens[$i + 1]] = true;
            }
            if ($i + 2 < $n) {
                $grams[$tokens[$i].$tokens[$i + 1].$tokens[$i + 2]] = true;
            }
        }
        // Pieces of a model the customer may write short ("zd421" for ZD421t): letters and digits together, ≥ 3.
        $pieces = array_keys(array_filter($singles + $pairs, fn ($v, $w) => mb_strlen($w) >= 3 && preg_match('/\p{L}/u', $w) && preg_match('/\p{N}/u', $w), ARRAY_FILTER_USE_BOTH));

        $direct = [];
        $brands = [];
        foreach ($index as $row) {
            $score = 0;
            if (self::mentioned($row['key'], $singles, $grams)) {
                $score = 10;
            } elseif (self::mentioned($row['model'], $singles, $grams)) {
                $score = 8;
            } else {
                foreach ($pieces as $w) {
                    if (str_contains($row['model'], (string) $w)) {
                        $score = 5;
                        break;
                    }
                }
            }
            if ($score) {
                $direct[$row['id']] = $score;
            }
            if ($row['brand'] !== '' && isset($grams[str_replace(' ', '', $row['brand'])])) {
                $brands[$row['brand']] = true;
            }
        }
        arsort($direct);
        $named = array_slice(array_keys($direct), 0, self::MAX_DIRECT);
        $ids = $named;

        // The accessories and the cheaper / better neighbours of the best named products.
        $byId = array_column($index, null, 'id');
        $byKey = array_column($index, null, 'key');
        foreach (array_slice($named, 0, 5) as $id) {
            foreach ($byId[$id]['accessories'] as $sku) {
                if ($a = $byKey[self::key($sku)] ?? null) {
                    $ids[] = $a['id'];
                }
            }
            foreach (array_filter(self::nearestIds($byId[$id], $index)) as $neighbour) {
                $ids[] = $neighbour;
            }
        }

        // The categories the customer asks about, then those of the named products.
        $categories = [];
        foreach (self::CATEGORY_WORDS as $category => $stems) {
            foreach ($stems as $stem) {
                if ($stem[0] === '=' ? isset($singles[substr($stem, 1)]) : str_contains($text, $stem)) {
                    $categories[$category] = true;
                    break;
                }
            }
        }
        if (! isset($categories['label_printer']) && ! isset($categories['receipt_printer'])) {
            foreach (self::PRINTER_WORDS as $stem) {
                if (str_contains($text, $stem)) {
                    $categories += ['label_printer' => true, 'receipt_printer' => true];
                    break;
                }
            }
        }
        foreach ($named as $id) {
            $categories[$byId[$id]['category']] = true;
        }
        foreach (array_slice(array_keys($categories), 0, 3) as $category) {
            $rows = array_values(array_filter($index, fn ($r) => $r['category'] === $category && ! in_array($r['id'], $ids, true)));
            if ($brands) {
                $own = array_values(array_filter($rows, fn ($r) => isset($brands[$r['brand']])));
                $rows = $own ?: $rows;
            }
            $ids = [...$ids, ...self::spread($rows, self::PER_CATEGORY)];
        }
        // A brand alone ("anything from Zebra?").
        if (! $categories && $brands) {
            $ids = [...$ids, ...self::spread(array_values(array_filter($index, fn ($r) => isset($brands[$r['brand']]))), self::PER_CATEGORY)];
        }

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_PRODUCTS);
    }

    /** @return array{0: ?int, 1: ?int} the cheaper and better neighbours of $row (nearest end-user price, same category, not out of stock) */
    private static function nearestIds(array $row, array $index): array
    {
        if ($row['price'] === null) {
            return [null, null];
        }
        $cheaper = $better = null;
        foreach ($index as $r) {
            if ($r['category'] !== $row['category'] || $r['id'] === $row['id'] || $r['price'] === null || $r['stock'] === 'out') {
                continue;
            }
            if ($r['price'] < $row['price'] && (! $cheaper || $r['price'] > $cheaper['price'])) {
                $cheaper = $r;
            }
            if ($r['price'] > $row['price'] && (! $better || $r['price'] < $better['price'])) {
                $better = $r;
            }
        }

        return [$cheaper['id'] ?? null, $better['id'] ?? null];
    }

    /** Up to $n rows spread over the price range (available ones first), so cheap and better models are both there. */
    private static function spread(array $rows, int $n): array
    {
        $usable = array_values(array_filter($rows, fn ($r) => $r['stock'] !== 'out'));
        $rows = count($usable) >= min($n, count($rows)) ? $usable : $rows;
        usort($rows, fn ($a, $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX));
        if (count($rows) <= $n) {
            return array_column($rows, 'id');
        }
        $picked = [];
        for ($i = 0; $i < $n; $i++) {
            $picked[] = $rows[(int) round($i * (count($rows) - 1) / ($n - 1))]['id'];
        }

        return $picked;
    }

    public static function key(string $sku): string
    {
        return mb_strtolower((string) preg_replace('/[\s\-_.\/]+/u', '', $sku));
    }

    /**
     * A small index of the available products (cached per load): id, sku, brand, model, category, end-user price,
     * stock, accessories. Keyed by the lowercase SKU.
     *
     * @return array<string, array{id: int, sku: string, key: string, brand: string, model: string, category: string, price: ?int, stock: string, accessories: list<string>}>
     */
    public static function index(?CatalogSetting $settings = null): array
    {
        $settings ??= CatalogSetting::current();
        // Every good load is a new version (two loads in the same second with the same count included).
        $version = (int) CatalogLoad::where('status', 'ok')->max('id').':'.($settings->last_success_at?->getTimestamp() ?? 0).':'.CatalogProduct::available()->count();

        return Cache::remember("catalog-prompt-index:{$version}", 600, function () {
            $index = [];
            CatalogProduct::available()->select(['id', 'sku', 'brand', 'model', 'category', 'prices', 'stock_status', 'accessories'])
                ->orderBy('id')->chunk(1000, function ($chunk) use (&$index) {
                    foreach ($chunk as $p) {
                        $index[mb_strtolower($p->sku)] = [
                            'id' => $p->id, 'sku' => $p->sku, 'key' => self::key($p->sku),
                            'brand' => mb_strtolower(trim((string) $p->brand)), 'model' => self::key((string) $p->model),
                            'category' => $p->category, 'price' => is_int($p->prices['end_user'] ?? null) && $p->prices['end_user'] > 0 ? $p->prices['end_user'] : null,
                            'stock' => $p->stock_status, 'accessories' => array_values(array_filter((array) $p->accessories, 'is_string')),
                        ];
                    }
                });

            return $index;
        });
    }
}
