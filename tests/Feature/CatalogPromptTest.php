<?php

namespace Tests\Feature;

use App\Models\CatalogClient;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\Catalog\CatalogPrompt;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The PRODUCT CATALOG section of the sales assistant's prompt. */
class CatalogPromptTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> what the fake Claude got */
    public array $calls = [];

    private function product(string $sku, string $model, string $category, int $end, array $over = []): CatalogProduct
    {
        return CatalogProduct::create($over + [
            'sku' => $sku, 'brand' => 'Zebra', 'model' => $model, 'category' => $category, 'name' => ['ru' => $model],
            'prices' => ['end_user' => $end, 'dealer' => $end - 300000, 'partner' => $end - 500000],
            'stock_status' => 'in_stock', 'stock_qty' => 7, 'warranty_months' => 12, 'params' => ['print_width_mm' => 104],
            'is_active' => true, 'in_feed' => true,
        ]);
    }

    private function catalog(): void
    {
        $this->product('ZD230-203', 'ZD230', 'label_printer', 4100000);
        $this->product('ZD421T-203', 'ZD421t', 'label_printer', 6500000, ['accessories' => ['RIB-110']]);
        $this->product('ZT411-203', 'ZT411', 'label_printer', 19000000);
        $this->product('RIB-110', 'Ribbon wax 110x300', 'ribbon', 95000, ['brand' => 'BDB']);
        $this->product('DS2208', 'DS2208', 'scanner', 1500000);
        $this->product('100234', 'Scale A', 'scale', 2000000, ['brand' => 'CAS']);
        CatalogSetting::current()->forceFill(['last_success_at' => now()])->save();
    }

    public function test_no_catalog_no_section(): void
    {
        $this->assertSame('', CatalogPrompt::section(null, 'ZD421 narxi?')['text']);
    }

    public function test_end_user_sees_only_the_end_user_price(): void
    {
        $this->catalog();
        $text = CatalogPrompt::section(null, 'ZD421t narxi qancha?')['text'];

        $this->assertStringContainsString('"sku":"ZD421T-203"', $text);
        $this->assertStringContainsString('"price":"6 500 000"', $text);
        $this->assertStringNotContainsString('6 200 000', $text, 'no dealer price');
        $this->assertStringNotContainsString('6 000 000', $text, 'no partner price');
        $this->assertStringNotContainsString('dealer_price', $text);
        $this->assertStringContainsString('an end user', $text);
    }

    public function test_alternatives_and_accessory_come_with_the_named_model(): void
    {
        $this->catalog();
        $section = CatalogPrompt::section(null, 'Нужен ZD421t');

        $this->assertSame(['ZD421T-203', 'RIB-110', 'ZD230-203', 'ZT411-203'], $section['info']['products']);
        $this->assertStringContainsString('"cheaper":"ZD230-203","better":"ZT411-203"', $section['text']);
        $this->assertStringContainsString('"accessories":["RIB-110"]', $section['text']);
        $this->assertNotContains('DS2208', $section['info']['products'], 'other categories stay out');
    }

    public function test_dealer_and_partner_need_a_verified_phone(): void
    {
        $this->catalog();
        CatalogClient::create(['phone' => '+998901234567', 'price_group' => 'dealer', 'company' => 'Oila']);
        $user = TelegramUser::create(['telegram_id' => 555, 'phone' => '+998901234567']);

        $typed = CatalogPrompt::section($user, 'ZD421t')['text'];
        $this->assertStringNotContainsString('6 200 000', $typed, 'a typed phone gets the end-user price');

        $user->update(['phone_verified_at' => now()]);
        $dealer = CatalogPrompt::section($user, 'ZD421t')['text'];
        $this->assertStringContainsString('"price":"6 200 000","retail_price":"6 500 000"', $dealer);
        $this->assertStringNotContainsString('6 000 000', $dealer, 'a dealer never sees the partner price');
        $this->assertStringContainsString('a DEALER', $dealer);

        CatalogClient::where('phone', '+998901234567')->update(['price_group' => 'partner']);
        $partner = CatalogPrompt::section($user, 'ZD421t')['text'];
        $this->assertStringContainsString('"price":"6 000 000","dealer_price":"6 200 000","retail_price":"6 500 000"', $partner);
    }

    public function test_stale_catalog_gives_no_price_or_stock(): void
    {
        $this->catalog();
        $this->travel(25)->hours();
        $text = CatalogPrompt::section(null, 'ZD421t')['text'];

        $this->assertStringContainsString('OUT OF DATE', $text);
        $this->assertStringContainsString('"sku":"ZD421T-203"', $text, 'models and params are still there');
        $this->assertStringNotContainsString('6 500 000', $text);
        $this->assertStringNotContainsString('"stock"', $text);
        $this->assertStringNotContainsString('"qty"', $text);
    }

    public function test_category_words_and_hidden_products(): void
    {
        $this->catalog();
        CatalogProduct::where('sku', 'ZT411-203')->update(['in_feed' => false]);

        $info = CatalogPrompt::section(null, 'этикетка принтер керак')['info'];
        $this->assertEqualsCanonicalizing(['ZD230-203', 'ZD421T-203'], array_values(array_intersect($info['products'], ['ZD230-203', 'ZD421T-203', 'ZT411-203'])));
        $this->assertSame(['DS2208'], CatalogPrompt::section(null, 'Сканер нужен')['info']['products']);
    }

    public function test_a_phone_number_does_not_match_a_numeric_code(): void
    {
        $this->catalog();
        $this->assertNotContains('100234', CatalogPrompt::section(null, 'Мой номер +998 90 100 234 56')['info']['products']);
        $this->assertContains('100234', CatalogPrompt::section(null, 'артикул 100234')['info']['products']);
    }

    public function test_the_live_turn_gets_the_section(): void
    {
        $this->catalog();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => 's', 'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private CatalogPromptTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system');

                return ['text' => json_encode(['reply' => 'Telefon raqamingiz?', 'language' => 'uz', 'ask_phone_button' => true, 'stage' => 'contacts',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false]),
                    'model' => $model, 'input_tokens' => 1000, 'output_tokens' => 100];
            }
        });

        $this->postJson('/telegram/webhook', ['update_id' => 1, 'message' => ['message_id' => 1, 'date' => now()->getTimestamp(), 'text' => 'ZD421t narxi qancha?',
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]], ['X-Telegram-Bot-Api-Secret-Token' => 's'])->assertOk();

        $this->assertCount(1, $this->calls);
        $system = $this->calls[0]['system'];
        $this->assertStringContainsString('# PRODUCT CATALOG', $system);
        $this->assertStringContainsString('"price":"6 500 000"', $system);
        $this->assertStringNotContainsString('6 200 000', $system);
        $this->assertLessThan(strpos($system, '# YOUR ANSWER (JSON)'), strpos($system, '# PRODUCT CATALOG'));
    }
}
