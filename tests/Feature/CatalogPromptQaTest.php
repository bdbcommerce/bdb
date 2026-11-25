<?php

namespace Tests\Feature;

use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\TelegramUser;
use App\Services\Catalog\CatalogPrompt;
use App\Services\SalesAssistant\SalesAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** QA: edge cases of the PRODUCT CATALOG prompt section. */
class CatalogPromptQaTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, string $model, string $category, ?int $end, array $over = []): CatalogProduct
    {
        return CatalogProduct::create($over + [
            'sku' => $sku, 'brand' => 'Zebra', 'model' => $model, 'category' => $category, 'name' => ['ru' => $model],
            'prices' => $end === null ? [] : ['end_user' => $end, 'dealer' => $end - 300000, 'partner' => $end - 500000],
            'stock_status' => 'in_stock', 'stock_qty' => 7, 'is_active' => true, 'in_feed' => true,
        ]);
    }

    private function fresh(): void
    {
        CatalogSetting::current()->forceFill(['last_success_at' => now()])->save();
    }

    private function user(string $group): TelegramUser
    {
        CatalogClient::create(['phone' => '+998901112233', 'price_group' => $group, 'company' => 'X']);

        return TelegramUser::create(['telegram_id' => 9001, 'phone' => '+998901112233', 'phone_verified_at' => now()]);
    }

    // ---- price groups ----

    public function test_dealer_never_sees_partner_price_in_any_listed_product(): void
    {
        $this->product('ZD230-203', 'ZD230', 'label_printer', 4100000);
        $this->product('ZD421T-203', 'ZD421t', 'label_printer', 6500000, ['accessories' => ['RIB-110']]);
        $this->product('ZT411-203', 'ZT411', 'label_printer', 19000000);
        $this->product('RIB-110', 'Ribbon', 'ribbon', 900000);
        $this->fresh();

        $text = CatalogPrompt::section($this->user('dealer'), 'ZD421t, ribbon, принтер этикеток')['text'];
        foreach ([3600000, 6000000, 18500000, 400000] as $partner) {
            $this->assertStringNotContainsString(CatalogPrompt::money($partner), $text);
        }
        $this->assertStringNotContainsString('dealer_price', $text);
    }

    public function test_end_user_price_missing_and_null_higher_prices(): void
    {
        $this->product('NOEND', 'NoEnd 1', 'scanner', null, ['prices' => ['dealer' => 1000000, 'partner' => 900000]]);
        $this->product('ONLYEND', 'OnlyEnd 2', 'scanner', null, ['prices' => ['end_user' => 2000000, 'dealer' => null, 'partner' => null]]);
        $this->fresh();

        $end = CatalogPrompt::section(null, 'NOEND ONLYEND')['text'];
        $this->assertStringNotContainsString('1 000 000', $end, 'no dealer price for an end user when end_user is missing');
        $this->assertStringNotContainsString('900 000', $end);

        $partner = CatalogPrompt::section($this->user('partner'), 'ONLYEND')['text'];
        $this->assertStringContainsString('"sku":"ONLYEND","name":"Zebra OnlyEnd 2","category":"scanner","price":"2 000 000","stock"', $partner);
    }

    /** DEFECT candidate: a 0 end-user price (allowed by the importer) is shown to Claude as a real price. */
    public function test_zero_price_is_not_quoted_as_a_price(): void
    {
        $this->product('FREE-1', 'Free 1', 'scanner', null, ['prices' => ['end_user' => 0]]);
        $this->fresh();

        $this->assertStringNotContainsString('"price":"0"', CatalogPrompt::section(null, 'FREE-1')['text']);
    }

    // ---- hidden / inactive ----

    public function test_inactive_or_hidden_products_never_leak_as_alternative_or_accessory(): void
    {
        $this->product('P-1', 'Pa100', 'label_printer', 4000000, ['accessories' => ['ACC-OFF', 'ACC-GONE']]);
        $this->product('P-CHEAP', 'Pc50', 'label_printer', 3000000, ['is_active' => false]);
        $this->product('P-BETTER', 'Pb900', 'label_printer', 9000000, ['in_feed' => false]);
        $this->product('ACC-OFF', 'Acc off', 'ribbon', 10000, ['is_active' => false]);
        $this->product('ACC-GONE', 'Acc gone', 'ribbon', 10000, ['in_feed' => false]);
        $this->fresh();

        $section = CatalogPrompt::section(null, 'Pa100');
        $this->assertSame(['P-1'], $section['info']['products']);
        foreach (['P-CHEAP', 'P-BETTER', 'ACC-OFF', 'ACC-GONE'] as $sku) {
            $this->assertStringNotContainsString($sku, $section['text']);
        }
    }

    // ---- selection ----

    public function test_cap_and_no_duplicates(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->product("SC-{$i}", "Ms{$i}x", 'scanner', 1000000 + $i * 10000, ['accessories' => ['SC-1', 'SC-2']]);
        }
        $this->fresh();
        $text = implode(' ', array_map(fn ($i) => "Ms{$i}x", range(1, 40)));

        $products = CatalogPrompt::section(null, $text.' сканер')['info']['products'];
        $this->assertLessThanOrEqual(CatalogPrompt::MAX_PRODUCTS, count($products));
        $this->assertSame($products, array_values(array_unique($products)));
    }

    /** DEFECT candidate: a quantity ("200 рулонов") matches any model containing "200" as if the customer named it. */
    public function test_quantity_does_not_count_as_a_named_model(): void
    {
        $this->product('TE200', 'TE200', 'label_printer', 3000000);
        $this->product('LBL-58x40', 'Label 58x40', 'label', 50000);
        $this->fresh();

        $products = CatalogPrompt::section(null, 'Нужно 200 рулонов этикеток 58x40')['info']['products'];
        $this->assertSame('LBL-58x40', $products[0] ?? null, 'the label is the first match');
        $this->assertNotSame('TE200', $products[0] ?? null);
    }

    /** DEFECT candidate: the pieces of a phone number written with spaces match models containing them. */
    public function test_phone_pieces_do_not_match_a_model(): void
    {
        $this->product('HR-1234', 'HR1234', 'scanner', 1500000);
        $this->product('DS-998X', 'DS998X', 'scanner', 1500000);
        $this->fresh();

        $products = CatalogPrompt::section(null, 'Мой номер +998 90 123 45 67, перезвоните')['info']['products'];
        $this->assertSame([], $products);
    }

    /** DEFECT candidate: letters + digits are matched across word borders ("kassa 58" → "a58"). */
    public function test_model_is_not_matched_across_words(): void
    {
        $this->product('A58', 'A58', 'receipt_printer', 1200000);
        $this->fresh();

        $this->assertSame([], CatalogPrompt::section(null, 'Kassa 58 mm lenta bormi?')['info']['products']);
    }

    public function test_brand_preference(): void
    {
        $this->product('Z-1', 'Zs1', 'scanner', 1000000);
        $this->product('H-1', 'Hs1', 'scanner', 1100000, ['brand' => 'Honeywell']);
        $this->product('H-2', 'Hs2', 'scanner', 1900000, ['brand' => 'Honeywell']);
        $this->fresh();

        $this->assertEqualsCanonicalizing(['H-1', 'H-2'], CatalogPrompt::section(null, 'Honeywell skaner kerak')['info']['products']);
    }

    /** Accessory written differently from its SKU in 1C ("RIB110" vs "RIB-110"). */
    public function test_accessory_written_without_dash(): void
    {
        $this->product('ZD421T-203', 'ZD421t', 'label_printer', 6500000, ['accessories' => ['RIB110']]);
        $this->product('RIB-110', 'Ribbon', 'ribbon', 95000);
        $this->fresh();

        $section = CatalogPrompt::section(null, 'ZD421t');
        $this->assertContains('RIB-110', $section['info']['products']);
        $this->assertStringContainsString('"accessories":["RIB-110"]', $section['text'], 'the accessory is listed and linked');
    }

    // ---- category words ----

    /** @return array<string, array{string, string}> */
    public static function words(): array
    {
        return [
            'ru ленту для чеков' => ['Нужно ленту для чеков 80 мм', 'receipt_paper'],
            'ru термобумага' => ['Есть термобумага 57 мм?', 'receipt_paper'],
            'uz latin chek lentasi' => ['Chek lentasi kerak', 'receipt_paper'],
            'uz cyrillic чек лентаси' => ['Чек лентаси керак', 'receipt_paper'],
            'uz latin kassa lentasi' => ['kassa lentasi bormi', 'receipt_paper'],
            'uz latin TSD typographic apostrophe' => ['ma’lumot yig‘ish terminali kerak', 'tsd'],
            'uz latin tamir without apostrophe' => ['printer tamirlash kerak', 'service'],
            'uz latin etiketka' => ['etiketka kerak 58x40', 'label'],
            'uz cyrillic ёрлиқ' => ['ёрлиқ керак', 'label'],
            'uz latin tarozi' => ['tarozi bormi', 'scale'],
            'uz cyrillic тарози' => ['тарози борми', 'scale'],
            'ru весами' => ['что с весами', 'scale'],
            'en barcode printer' => ['need a barcode printer', 'label_printer'],
            'ru чековый принтер' => ['чековый принтер', 'receipt_printer'],
        ];
    }

    #[DataProvider('words')]
    public function test_category_words(string $text, string $category): void
    {
        foreach (array_keys(CatalogProduct::CATEGORIES) as $i => $c) {
            $this->product("C-{$c}", "Item{$i}q", $c, 100000 + $i);
        }
        $this->fresh();

        $this->assertContains("C-{$category}", CatalogPrompt::section(null, $text)['info']['products'], $text);
    }

    // ---- cache ----

    public function test_price_change_in_the_same_second_is_shown(): void
    {
        $this->product('ZD421T-203', 'ZD421t', 'label_printer', 6500000);
        $this->fresh();
        $this->assertStringContainsString('6 500 000', CatalogPrompt::section(null, 'ZD421t')['text']);

        CatalogProduct::where('sku', 'ZD421T-203')->update(['prices' => json_encode(['end_user' => 7000000])]);
        $this->assertStringContainsString('"price":"7 000 000"', CatalogPrompt::section(null, 'ZD421t')['text']);
    }

    /** DEFECT candidate: a new load in the same second with the same count keeps the old index for 10 min. */
    public function test_replaced_product_same_count_same_second(): void
    {
        $this->product('OLD-1', 'Old111', 'scanner', 1000000);
        $this->fresh();
        CatalogPrompt::section(null, 'Old111');

        CatalogProduct::where('sku', 'OLD-1')->update(['in_feed' => false]);
        $this->product('NEW-1', 'New222', 'scanner', 1000000);
        CatalogLoad::create(['source' => 'push', 'trigger' => 'push', 'status' => 'ok']); // every good load is logged

        $this->assertSame(['NEW-1'], CatalogPrompt::section(null, 'New222')['info']['products']);
    }

    // ---- stale ----

    public function test_stale_has_no_lead_time_or_price_anywhere(): void
    {
        $this->product('P-1', 'Pa100', 'label_printer', 4000000, ['lead_time_days' => 14, 'stock_status' => 'to_order']);
        $this->product('P-2', 'Pa200', 'label_printer', 5000000);
        CatalogSetting::current()->forceFill(['last_success_at' => now()->subHours(30)])->save();

        $text = CatalogPrompt::section($this->user('partner'), 'Pa100')['text'];
        $this->assertStringContainsString('OUT OF DATE', $text);
        foreach (['lead_time', '"price"', 'to_order', '4 000 000', '3 500 000', 'dealer_price', 'retail_price'] as $s) {
            $this->assertStringNotContainsString($s, $text);
        }
    }

    // ---- sandbox ----

    public function test_sandbox_catalog_is_end_user_even_with_items(): void
    {
        $this->product('ZD421T-203', 'ZD421t', 'label_printer', 6500000);
        $this->fresh();

        $c = SalesAssistant::catalog(null, '', ['items' => [['product' => 'ZD421t', 'details' => '2 dona']]]);
        $this->assertStringContainsString('"price":"6 500 000"', $c['text']);
        $this->assertSame('end_user', $c['info']['price_group']);
        $this->assertStringNotContainsString('6 200 000', $c['text']);
    }

    // ---- size / performance ----

    public function test_five_thousand_products(): void
    {
        $now = now();
        $cats = array_keys(CatalogProduct::CATEGORIES);
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = ['sku' => sprintf('%08d', $i), 'brand' => ['Zebra', 'Honeywell', 'Datalogic', 'Xprinter'][$i % 4], 'model' => 'M'.$i.'q',
                'category' => $cats[$i % count($cats)], 'name' => json_encode(['ru' => "Товар {$i}"]),
                'prices' => json_encode(['end_user' => 100000 + $i * 1000, 'dealer' => 90000 + $i * 1000, 'partner' => 80000 + $i * 1000]),
                'stock_status' => 'in_stock', 'stock_qty' => 5, 'params' => json_encode(['print_width_mm' => 104, 'resolution_dpi' => 203, 'connectivity' => ['usb', 'lan', 'wifi', 'bluetooth']]),
                'accessories' => json_encode([sprintf('%08d', $i + 1)]),
                'is_active' => true, 'in_feed' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            CatalogProduct::insert($chunk);
        }
        $this->fresh();
        $text = 'Нужен принтер этикеток и сканер Honeywell, M120q и M121q, весы, мой номер +998 90 123 45 67, 200 штук';

        $t = microtime(true);
        $first = CatalogPrompt::section(null, $text);
        $cold = microtime(true) - $t;
        $t = microtime(true);
        CatalogPrompt::section(null, $text);
        $warm = microtime(true) - $t;

        fwrite(STDERR, sprintf("\n[QA] 5000 products: cold %.0f ms, warm %.0f ms, section %d chars, %d products, cached index %d KB, products %s\n", $cold * 1000, $warm * 1000,
            mb_strlen($first['text']), count($first['info']['products']), strlen(serialize(CatalogPrompt::index())) / 1024, implode(',', $first['info']['products'])));
        $this->assertLessThanOrEqual(30, count($first['info']['products']));
        $this->assertLessThan(20000, mb_strlen($first['text']));
        $this->assertLessThan(1.5, $warm);
    }

    /** Observation: a plain "printer" names no category; "компьютер" in "подключить к компьютеру" pulls POS products. */
    public function test_generic_words(): void
    {
        foreach (array_keys(CatalogProduct::CATEGORIES) as $i => $c) {
            $this->product("C-{$c}", "Item{$i}q", $c, 100000 + $i);
        }
        $this->fresh();

        $this->assertNotContains('C-pos', CatalogPrompt::section(null, 'как подключить сканер к компьютеру')['info']['products'], 'компьютер pulls POS');
        $this->assertNotSame([], CatalogPrompt::section(null, 'printer kerak')['info']['products'], 'uz: printer kerak');
    }

    /** DEFECT candidate: named products of 3 categories push out the category the customer asks for. */
    public function test_asked_category_kept_when_three_products_are_named(): void
    {
        $this->product('ZD421T-203', 'ZD421t', 'label_printer', 6500000);
        $this->product('RIB-110', 'Rb110x', 'ribbon', 95000);
        $this->product('XP-Q80', 'Xq80c', 'receipt_printer', 1500000);
        $this->product('H-1', 'Hs1', 'scanner', 1100000, ['brand' => 'Honeywell']);
        $this->fresh();

        $products = CatalogPrompt::section(null, 'ZD421t, Rb110x, Xq80c va Honeywell skaner kerak')['info']['products'];
        $this->assertContains('H-1', $products, 'the asked scanner is missing');
    }

    /** A short SKU with a dash ("P-1") typed exactly as in 1C. */
    public function test_short_sku_with_dash(): void
    {
        $this->product('P-1', 'Alpha', 'scanner', 1000000);
        $this->fresh();

        $this->assertSame(['P-1'], CatalogPrompt::section(null, 'P-1 narxi')['info']['products']);
    }
}
