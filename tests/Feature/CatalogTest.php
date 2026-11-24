<?php

namespace Tests\Feature;

use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = CatalogSetting::current()->regenerateToken();
    }

    private function product(string $sku, array $over = []): array
    {
        return array_replace_recursive([
            'sku' => $sku, 'brand' => 'Zebra', 'model' => "M-{$sku}", 'category' => 'label_printer',
            'name' => ['ru' => "Принтер {$sku}"],
            'prices' => ['end_user' => 6500000, 'dealer' => 6100000, 'partner' => 5800000],
            'stock' => ['status' => 'in_stock', 'qty' => 12],
            'lead_time_days' => null, 'warranty_months' => 12,
            'params' => ['print_width_mm' => 104, 'connectivity' => ['usb', 'lan']],
            'use_cases' => ['retail'], 'accessories' => ['RIB-1'], 'active' => true,
        ], $over);
    }

    private function push(array $payload, ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? $this->token))
            ->postJson('/api/catalog', $payload);
    }

    private function skus(int $n, string $prefix = 'P'): array
    {
        return array_map(fn ($i) => $this->product($prefix.$i), range(1, $n));
    }

    public function test_push_imports_products_and_clients(): void
    {
        $response = $this->push(['products' => [$this->product('A1'), $this->product('A2')], 'clients' => [
            ['price_group' => 'dealer', 'company' => 'Oila Market', 'inn' => '301 234 567', 'phones' => ['+998 90 123-45-67', '901112233']],
        ]]);

        $response->assertOk()->assertJson(['status' => 'ok', 'accepted' => 2, 'hidden' => 0, 'clients' => 2]);
        $p = CatalogProduct::where('sku', 'A1')->first();
        $this->assertSame(6500000, $p->prices['end_user']);
        $this->assertSame(['usb', 'lan'], $p->params['connectivity']);
        $this->assertTrue($p->in_feed);
        $this->assertSame('dealer', CatalogClient::where('phone', '+998901112233')->value('price_group'));
        $this->assertSame('301234567', CatalogClient::where('phone', '+998901234567')->value('inn'));
        $this->assertTrue(CatalogSetting::current()->isFresh());
    }

    public function test_push_needs_the_right_token_and_push_mode(): void
    {
        $this->push(['products' => [$this->product('A1')]], 'wrong')->assertStatus(401);
        $this->postJson('/api/catalog', ['products' => []])->assertStatus(401);

        CatalogSetting::current()->update(['source' => 'pull']);
        $this->push(['products' => [$this->product('A1')]])->assertStatus(409);
        $this->assertSame(0, CatalogProduct::count());
    }

    public function test_wrong_tokens_are_throttled_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->push(['products' => []], 'bad'.$i)->assertStatus(401);
        }
        $this->push(['products' => [$this->product('A1')]])->assertStatus(429);
    }

    public function test_bad_products_are_listed_and_the_rest_accepted(): void
    {
        $response = $this->push(['products' => [
            $this->product('OK1'),
            $this->product('BAD1', ['prices' => ['end_user' => '6 500 000']]),
            $this->product('BAD2', ['category' => 'printer']),
            $this->product('BAD3', ['stock' => ['status' => 'maybe']]),
            $this->product('BAD4', ['params' => ['Ширина' => 104]]),
            'not an object',
        ]]);

        $response->assertOk()->assertJson(['accepted' => 1]);
        $this->assertCount(5, $response->json('rejected'));
        $this->assertSame('BAD1', $response->json('rejected.0.sku'));
        $this->assertSame(['OK1'], CatalogProduct::pluck('sku')->all());
        $this->assertSame(5, CatalogLoad::latest('id')->first()->rejected_count);
    }

    public function test_duplicate_sku_refuses_the_whole_load(): void
    {
        $this->push(['products' => [$this->product('A1'), $this->product('a1')]])
            ->assertStatus(422)->assertJson(['status' => 'refused']);
        $this->assertSame(0, CatalogProduct::count());
    }

    public function test_missing_products_are_hidden_and_come_back(): void
    {
        $this->push(['products' => [$this->product('A1'), $this->product('A2'), $this->product('A3')]])->assertOk();
        $this->push(['products' => [$this->product('A1'), $this->product('A2', ['prices' => ['end_user' => 7000000]])]])
            ->assertOk()->assertJson(['hidden' => 1]);

        $this->assertSame(['A1', 'A2'], CatalogProduct::available()->orderBy('sku')->pluck('sku')->all());
        $this->assertFalse(CatalogProduct::where('sku', 'A3')->value('in_feed'));
        $this->assertSame(7000000, CatalogProduct::where('sku', 'A2')->first()->prices['end_user']);

        $this->push(['products' => [$this->product('A1'), $this->product('A2'), $this->product('A3')]])->assertOk();
        $this->assertSame(3, CatalogProduct::available()->count());
    }

    public function test_a_much_smaller_load_is_refused(): void
    {
        $this->push(['products' => $this->skus(20)])->assertOk();
        $this->push(['products' => $this->skus(9)])->assertStatus(422)->assertJson(['status' => 'refused']);
        $this->assertSame(20, CatalogProduct::available()->count());

        $this->push(['products' => []])->assertStatus(422);
        $this->assertSame(20, CatalogProduct::available()->count());
    }

    public function test_clients_are_kept_when_a_load_has_no_clients_key(): void
    {
        $this->push(['products' => [$this->product('A1')], 'clients' => [['price_group' => 'partner', 'company' => 'X', 'phones' => ['+998901234567']]]]);
        $this->push(['products' => [$this->product('A1')]])->assertOk();
        $this->assertSame(1, CatalogClient::count());

        $this->push(['products' => [$this->product('A1')], 'clients' => []])->assertOk();
        $this->assertSame(0, CatalogClient::count());
    }

    public function test_price_group_needs_a_verified_phone(): void
    {
        CatalogClient::create(['phone' => '+998901234567', 'price_group' => 'dealer', 'company' => 'X']);
        $user = TelegramUser::create(['telegram_id' => 555, 'phone' => '+998901234567']);

        $this->assertSame('end_user', CatalogClient::priceGroupFor($user), 'a typed phone is not trusted');
        $user->update(['phone_verified_at' => now()]);
        $this->assertSame('dealer', CatalogClient::priceGroupFor($user));
        $this->assertSame('end_user', CatalogClient::priceGroupFor(null));
    }

    public function test_price_visibility_ladder(): void
    {
        $p = new CatalogProduct(['prices' => ['end_user' => 100, 'dealer' => 90, 'partner' => 80]]);

        $this->assertSame(['end_user' => 100], $p->pricesFor('end_user'));
        $this->assertSame(['end_user' => 100], $p->pricesFor(null));
        $this->assertSame(['end_user' => 100, 'dealer' => 90], $p->pricesFor('dealer'));
        $this->assertSame(['end_user' => 100, 'dealer' => 90, 'partner' => 80], $p->pricesFor('partner'));
    }

    public function test_pull_loads_from_1c_with_basic_auth(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/bdb/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'secret-pass']);
        Http::fake(['1c.example.uz/*' => Http::response(['products' => [$this->product('A1')]])]);

        $this->artisan('catalog:pull')->assertSuccessful();

        $this->assertSame(1, CatalogProduct::count());
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('bdb_site:secret-pass')));
        $this->assertSame('pull', CatalogLoad::latest('id')->first()->source);
    }

    public function test_pull_failure_fails_the_task_and_hides_the_password(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/bdb/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'secret-pass']);
        Http::fake(['1c.example.uz/*' => Http::response(['error' => 'login secret-pass failed'], 401)]);

        $this->artisan('catalog:pull')->assertFailed();

        $load = CatalogLoad::latest('id')->first();
        $this->assertSame('failed', $load->status);
        $this->assertStringContainsString('HTTP 401', $load->error);
        $this->assertStringNotContainsString('secret-pass', $load->error);
    }

    public function test_pull_needs_https(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'http://1c.example.uz/x', 'pull_username' => 'u', 'pull_password' => 'p']);
        Http::fake();

        $this->artisan('catalog:pull')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_stale_catalog_fails_the_task(): void
    {
        $this->push(['products' => [$this->product('A1')]])->assertOk();
        $this->artisan('catalog:pull')->assertSuccessful();

        $this->travel(25)->hours();
        $this->assertFalse(CatalogSetting::current()->isFresh());
        $this->artisan('catalog:pull')->assertFailed();
    }

    public function test_admin_page_and_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->push(['products' => [$this->product('A1')]]);

        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('M-A1')->assertSee('6 500 000');

        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'http://1c.example.uz', 'pull_username' => 'u', 'pull_password' => 'pass-1234', 'max_age_hours' => 24])
            ->assertSessionHasErrors('pull_url');

        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/x', 'pull_username' => 'u', 'pull_password' => 'pass-1234', 'max_age_hours' => 12])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/x', 'pull_username' => 'u', 'pull_password' => '', 'max_age_hours' => 12])
            ->assertSessionHasNoErrors();
        $settings = CatalogSetting::current();
        $this->assertSame('pass-1234', $settings->pull_password, 'an empty field keeps the saved password');
        $this->assertSame(12, $settings->max_age_hours);

        $this->actingAs($admin)->post(route('admin.catalog.token'))->assertSessionHas('catalog_token');
        $this->assertFalse(CatalogSetting::current()->tokenMatches($this->token), 'the old token stops working');
    }

    public function test_guests_cannot_open_the_admin_page(): void
    {
        $this->get(route('admin.catalog.index'))->assertRedirect();
    }
}
