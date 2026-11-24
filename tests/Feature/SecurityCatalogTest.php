<?php

namespace Tests\Feature;

use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Catalog\CatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Security review of the product catalog (push endpoint, pull, importer, admin page, price ladder). */
class SecurityCatalogTest extends TestCase
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
        return array_replace([
            'sku' => $sku, 'brand' => 'Zebra', 'model' => "M-{$sku}", 'category' => 'label_printer',
            'name' => ['ru' => "Printer {$sku}"],
            'prices' => ['end_user' => 100, 'dealer' => 90, 'partner' => 80],
            'stock' => ['status' => 'in_stock', 'qty' => 1], 'active' => true,
        ], $over);
    }

    private function push(array|string $payload, ?string $token = null)
    {
        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token), 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $body = is_string($payload) ? $payload : json_encode($payload);

        return $this->call('POST', '/api/catalog', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    public function test_non_admin_users_and_guests_are_refused_on_every_admin_catalog_route(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        foreach ([['get', 'admin.catalog.index'], ['put', 'admin.catalog.update'], ['post', 'admin.catalog.token'], ['post', 'admin.catalog.pull']] as [$method, $route]) {
            $this->{$method}(route($route), ['source' => 'push', 'max_age_hours' => 1])->assertRedirect();
            $this->assertNotSame(200, $this->actingAs($user)->{$method}(route($route), ['source' => 'push', 'max_age_hours' => 1])->status(), $route);
        }
        $this->assertTrue(CatalogSetting::current()->tokenMatches($this->token), 'a non-admin could not rotate the token');
    }

    public function test_push_without_token_or_with_empty_bearer_is_refused_and_imports_nothing(): void
    {
        $this->call('POST', '/api/catalog', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['products' => [$this->product('A1')]]))->assertStatus(401);
        $this->push(['products' => [$this->product('A1')]], '')->assertStatus(401);
        // a hash of the token (what the DB stores) is not a token
        $this->push(['products' => [$this->product('A1')]], hash('sha256', $this->token))->assertStatus(401);
        $this->assertSame(0, CatalogProduct::count());
    }

    public function test_no_token_configured_refuses_every_push(): void
    {
        CatalogSetting::current()->forceFill(['token_hash' => null])->save();
        $this->push(['products' => [$this->product('A1')]], 'anything')->assertStatus(401);
    }

    public function test_body_over_the_cap_is_refused_before_decoding(): void
    {
        $big = '{"products":['.str_repeat(' ', CatalogImporter::MAX_BYTES + 10).']}';
        $this->push($big)->assertStatus(413);
    }

    public function test_malformed_payloads_never_cause_a_500(): void
    {
        $bodies = [
            'not json', '[]', '"str"', '123', 'null', '{"products":"x"}', '{"products":{"a":1}}',
            str_repeat('[', 600).str_repeat(']', 600), // deeper than json_decode's default 512
            json_encode(['products' => [
                $this->product('S1', ['name' => 'plain string']),
                $this->product('S2', ['prices' => 'x']),
                $this->product('S3', ['prices' => [1, 2, 3]]),
                $this->product('S4', ['stock' => 'in_stock']),
                $this->product('S5', ['params' => ['a' => ['b' => ['c' => 1]]]]),
                $this->product('S6', ['params' => ['0' => 1]]),
                $this->product('S7', ['active' => 'true']),
                $this->product('S8', ['prices' => ['end_user' => 1e30]]),
                $this->product('S9', ['sku' => ['x']]),
                $this->product('S10', ['use_cases' => [['x']]]),
                $this->product('S11', ['accessories' => 'x']),
                $this->product('OK'),
            ], 'clients' => [
                'x', ['price_group' => 'end_user', 'company' => 'X', 'phones' => ['+998901234567']],
                ['price_group' => 'partner', 'company' => ['x'], 'phones' => ['+998901234567']],
                ['price_group' => 'partner', 'company' => 'X', 'inn' => true, 'phones' => [['x'], 123, 'abc', '+998901234567']],
                ['price_group' => 'partner', 'company' => 'X', 'phones' => 'x'],
            ]]),
        ];
        foreach ($bodies as $body) {
            $status = $this->push($body)->status();
            $this->assertNotSame(500, $status, mb_substr($body, 0, 60));
        }
        $this->assertSame(['OK'], CatalogProduct::pluck('sku')->all());
        $this->assertSame(['+998901234567' => 'partner'], CatalogClient::pluck('price_group', 'phone')->all(), 'end_user / bad entries never become a client row');
    }

    public function test_unknown_fields_are_not_stored(): void
    {
        $this->push(['products' => [$this->product('A1', ['in_feed' => false, 'id' => 999, 'created_at' => '2000-01-01', 'secret' => 'x'])]])->assertOk();
        $p = CatalogProduct::first();
        $this->assertNotSame(999, $p->id);
        $this->assertTrue($p->in_feed);
        $this->assertNotSame('2000', $p->created_at->format('Y'));
    }

    public function test_strings_from_1c_are_escaped_on_the_admin_page(): void
    {
        $x = '<img src=x onerror=alert(1)>';
        $this->push(['products' => [
            $this->product('<b>sku</b>', ['brand' => $x, 'model' => $x, 'name' => ['ru' => $x], 'params' => ['print_method' => $x, 'connectivity' => [$x]]]),
            $this->product('<i>bad</i>', ['category' => 'nope']),
        ], 'clients' => [['price_group' => 'dealer', 'company' => $x, 'phones' => ['<script>']]]])->assertOk();
        CatalogSetting::current()->forceFill(['last_error' => $x])->save();

        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($x, $html);
        $this->assertStringNotContainsString('<b>sku</b>', $html);
        $this->assertStringNotContainsString('<i>bad</i>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_push_alert_escapes_1c_text_and_never_contains_the_bot_token(): void
    {
        config(['services.telegram.bot_token' => '123456:FAKE_TEST_TOKEN_abcdef', 'services.telegram.alert_chat_id' => '-100']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $sku = '<a href="tg://x">123456:FAKE_TEST_TOKEN_abcdef</a>';
        $this->push(['products' => [$this->product($sku), $this->product($sku)]])->assertStatus(422);

        Http::assertSent(function (HttpRequest $r) {
            $text = (string) ($r['text'] ?? '');

            return str_contains($text, 'Product catalog') && ! str_contains($text, '<a href')
                && str_contains($text, '&lt;a href') && ! str_contains($text, 'FAKE_TEST_TOKEN');
        });
    }

    public function test_pull_never_follows_a_redirect_with_the_1c_login(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'secret-pass-1']);
        Http::fake([
            '1c.example.uz/*' => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
            'evil.example/*' => Http::response(['products' => [$this->product('E1')]]),
        ]);

        $this->artisan('catalog:pull')->assertFailed();

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example'));
        Http::assertSentCount(1);
        $this->assertSame(0, CatalogProduct::count());
    }

    public function test_pull_connection_errors_hide_the_password_and_username(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/catalog', 'pull_username' => 'bdb_site_user', 'pull_password' => 'secret-pass-1']);
        Http::fake(fn () => throw new ConnectionException('cURL error 7 for https://bdb_site_user:secret-pass-1@1c.example.uz/hs/catalog'));

        $this->artisan('catalog:pull')->assertFailed();

        $error = CatalogLoad::latest('id')->first()->error.CatalogSetting::current()->last_error;
        $this->assertStringNotContainsString('secret-pass-1', $error);
        $this->assertStringNotContainsString('bdb_site_user', $error);
    }

    /** FINDING (low): secrets shorter than 6 characters are not masked by AiErrorAlerts::withoutSecrets. */
    public function test_short_pull_password_is_masked_in_errors(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'Pw9!x']);
        Http::fake(['1c.example.uz/*' => Http::response(['error' => 'Wrong password Pw9!x for bdb_site'], 401)]);

        $this->artisan('catalog:pull')->assertFailed();

        $this->assertStringNotContainsString('Pw9!x', (string) CatalogLoad::latest('id')->first()->error);
    }

    public function test_password_and_token_hash_are_never_rendered_and_the_token_is_shown_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/x', 'pull_username' => 'u', 'pull_password' => 'very-secret-pw']);
        $settings = CatalogSetting::current();

        $html = $this->actingAs($admin)->get(route('admin.catalog.index'))->getContent();
        $this->assertStringNotContainsString('very-secret-pw', $html);
        $this->assertStringNotContainsString($settings->token_hash, $html);
        $this->assertArrayNotHasKey('pull_password', $settings->toArray());
        $this->assertArrayNotHasKey('token_hash', $settings->toArray());
        $this->assertNotSame('very-secret-pw', $settings->getRawOriginal('pull_password'), 'stored encrypted');

        // a validation error never flashes the typed password back
        $this->actingAs($admin)->from(route('admin.catalog.index'))
            ->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'http://bad', 'pull_username' => 'u', 'pull_password' => 'typed-secret-pw', 'max_age_hours' => 1])
            ->assertSessionHasErrors('pull_url');
        $this->assertNull(session()->getOldInput('pull_password'));

        CatalogSetting::current()->update(['source' => 'push']);
        $this->actingAs($admin)->post(route('admin.catalog.token'));
        $token = session('catalog_token');
        $this->assertStringContainsString($token, $this->actingAs($admin)->get(route('admin.catalog.index'))->getContent());
        $this->assertStringNotContainsString($token, $this->actingAs($admin)->get(route('admin.catalog.index'))->getContent(), 'shown once');
    }

    public function test_higher_prices_never_reach_a_lower_or_unknown_group(): void
    {
        $p = new CatalogProduct(['prices' => ['end_user' => 100, 'dealer' => 90, 'partner' => 80]]);
        foreach ([null, '', 'end_user', 'END_USER', 'Partner', 'admin', '0', 'partner ', 'customer'] as $group) {
            $this->assertSame(['end_user' => 100], $p->pricesFor($group), var_export($group, true));
        }
        $this->assertArrayNotHasKey('partner', $p->pricesFor('dealer'));
    }

    public function test_price_group_needs_the_phone_verified_by_telegram(): void
    {
        CatalogClient::create(['phone' => '+998901234567', 'price_group' => 'partner', 'company' => 'X']);

        $typed = TelegramUser::create(['telegram_id' => 1, 'phone' => '901234567']);
        $this->assertSame('end_user', CatalogClient::priceGroupFor($typed));

        $verifiedOther = TelegramUser::create(['telegram_id' => 2, 'phone' => '+998901111111', 'phone_verified_at' => now()]);
        $this->assertSame('end_user', CatalogClient::priceGroupFor($verifiedOther));

        $verified = TelegramUser::create(['telegram_id' => 3, 'phone' => '998901234567', 'phone_verified_at' => now()]);
        $this->assertSame('partner', CatalogClient::priceGroupFor($verified));

        $cleared = TelegramUser::create(['telegram_id' => 4, 'phone' => '', 'phone_verified_at' => now()]);
        $this->assertSame('end_user', CatalogClient::priceGroupFor($cleared));
    }

    public function test_push_is_rate_limited_per_ip_even_with_the_right_token(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->push(['products' => [$this->product('A1')]])->assertOk();
        }
        $this->push(['products' => [$this->product('A1')]])->assertStatus(429);
    }
}
