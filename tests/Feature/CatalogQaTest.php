<?php

namespace Tests\Feature;

use App\Models\CatalogClient;
use App\Models\CatalogLoad;
use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\User;
use App\Services\Catalog\CatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: edge cases of the 1C product catalog (push / pull / admin page). */
class CatalogQaTest extends TestCase
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
            'name' => ['ru' => "Принтер {$sku}"],
            'prices' => ['end_user' => 6500000, 'dealer' => 6100000, 'partner' => 5800000],
            'stock' => ['status' => 'in_stock', 'qty' => 12],
            'lead_time_days' => null, 'warranty_months' => 12,
            'params' => ['print_width_mm' => 104, 'connectivity' => ['usb', 'lan']],
            'use_cases' => ['retail'], 'accessories' => ['RIB-1'], 'active' => true,
        ], $over);
    }

    private function push(array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/catalog', $payload);
    }

    private function raw(string $body)
    {
        return $this->call('POST', '/api/catalog', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_response_shape_matches_the_spec(): void
    {
        $r = $this->push(['generated_at' => '2026-11-24T10:00:00+05:00', 'currency' => 'UZS', 'products' => [$this->product('A1')]]);

        $r->assertOk()->assertJsonStructure(['status', 'received', 'accepted', 'rejected', 'hidden']);
        $this->assertSame([], $r->json('rejected'));
        $this->assertArrayNotHasKey('error', $r->json());
        $this->assertArrayNotHasKey('clients', $r->json(), 'no clients key → clients not reported');
    }

    public function test_floats_as_prices(): void
    {
        $r = $this->raw('{"products":['
            .json_encode($this->product('F1', ['prices' => ['end_user' => 1]])).','
            .json_encode($this->product('F2', ['prices' => ['end_user' => 1]])).']}');
        $r->assertOk();

        // Whole floats (1C often serialises numbers as 6500000.0) are accepted, fractions are not.
        $body = json_encode(['products' => [$this->product('F1'), $this->product('F2')]]);
        $body = str_replace('"end_user":6500000', '"end_user":6500000.0', $body, $n);
        $body = preg_replace('/"dealer":6100000/', '"dealer":6100000.5', $body, 1);
        $r = $this->raw($body);
        $r->assertOk()->assertJson(['accepted' => 1]);
        $this->assertSame('F1', $r->json('rejected.0.sku'));
        $this->assertStringContainsString('prices.dealer', $r->json('rejected.0.error'));
        $this->assertSame(6500000, CatalogProduct::where('sku', 'F2')->first()->prices['end_user']);
        $this->assertIsInt(CatalogProduct::where('sku', 'F2')->first()->prices['end_user']);

        // Negative and huge numbers
        $r = $this->push(['products' => [
            $this->product('F1'), $this->product('F2'),
            $this->product('N1', ['prices' => ['end_user' => -1]]),
            $this->product('N2', ['prices' => ['end_user' => 1e30]]),
            $this->product('N3', ['prices' => ['end_user' => 0, 'dealer' => null, 'partner' => null]]),
        ]]);
        $r->assertOk()->assertJson(['accepted' => 3]);
        $this->assertSame(['end_user' => 0], CatalogProduct::where('sku', 'N3')->first()->prices);
    }

    public function test_unicode_names_and_skus(): void
    {
        $this->push(['products' => [
            $this->product('ТСД-1', ['brand' => 'Атол', 'name' => ['uz' => 'Oʻzbekcha nomi: shtrix-kod skaneri', 'ru' => 'Сканер «Атол» ёж', 'en' => 'Scanner ✓']]),
        ]])->assertOk()->assertJson(['accepted' => 1]);

        $p = CatalogProduct::where('sku', 'ТСД-1')->first();
        $this->assertSame('Сканер «Атол» ёж', $p->displayName('ru'));
        $this->assertSame('Oʻzbekcha nomi: shtrix-kod skaneri', $p->displayName('uz'));

        $this->actingAs($this->admin())->get(route('admin.catalog.index', ['q' => 'ТСД']))
            ->assertOk()->assertSee('ТСД-1')->assertSee('Сканер «Атол» ёж');
    }

    public function test_long_strings_are_rejected_per_product(): void
    {
        $r = $this->push(['products' => [
            $this->product('OK'),
            $this->product(str_repeat('S', 65)),
            $this->product('B', ['brand' => str_repeat('я', 65)]),
            $this->product('M', ['model' => str_repeat('я', 101)]),
            $this->product('N', ['name' => ['ru' => str_repeat('я', 256)]]),
            $this->product('P', ['params' => ['os' => str_repeat('x', 201)]]),
        ]]);

        $r->assertOk()->assertJson(['accepted' => 1]);
        $this->assertCount(5, $r->json('rejected'));
        $this->assertSame(64, mb_strlen($r->json('rejected.0.sku')) <= 65 ? 64 : 0);
    }

    public function test_params_with_nested_arrays(): void
    {
        $r = $this->push(['products' => [
            $this->product('OK', ['params' => ['connectivity' => ['usb', 'lan'], 'auto_cutter' => true, 'screen_inch' => 4.5, 'x' => null]]),
            $this->product('E', ['params' => []]),
            $this->product('NEST', ['params' => ['connectivity' => [['usb']]]]),
            $this->product('OBJ', ['params' => ['size' => ['w' => 1, 'h' => 2]]]),
            $this->product('LIST', ['params' => ['usb', 'lan']]),
            $this->product('BIG', ['params' => array_combine(array_map(fn ($i) => "k{$i}", range(1, 51)), range(1, 51))]),
            $this->product('STR', ['params' => 'usb']),
        ]]);

        $r->assertOk()->assertJson(['accepted' => 2]);
        $this->assertEqualsCanonicalizing(['NEST', 'OBJ', 'LIST', 'BIG', 'STR'], array_column($r->json('rejected'), 'sku'));
        $this->assertSame(4.5, CatalogProduct::where('sku', 'OK')->first()->params['screen_inch']);
        $this->assertNull(CatalogProduct::where('sku', 'E')->first()->params);

        // Admin page renders bool / list params
        $this->actingAs($this->admin())->get(route('admin.catalog.index'))->assertOk()->assertSee('auto_cutter: <span class="text-slate-900">yes</span>', false)
            ->assertSee('usb, lan');
    }

    public function test_odd_field_types_never_crash(): void
    {
        $r = $this->push(['products' => [
            $this->product('OK'),
            $this->product('S1', ['name' => 'Принтер']),
            $this->product('S2', ['prices' => 100]),
            $this->product('S3', ['stock' => 'in_stock']),
            $this->product('S4', ['active' => 'true']),
            $this->product('S5', ['use_cases' => 'retail']),
            $this->product('S6', ['accessories' => [1, 2]]),
            ['sku' => 12345],
            ['sku' => ['x']],
            null,
            42,
        ]]);

        $r->assertOk()->assertJson(['accepted' => 1, 'received' => 11]);
        $this->assertCount(10, $r->json('rejected'));
    }

    public function test_body_not_json_or_not_an_object(): void
    {
        $this->raw('{"products": [')->assertStatus(422)->assertJson(['status' => 'failed']);
        $this->raw('[1,2,3]')->assertStatus(422)->assertJson(['status' => 'failed']);
        $this->raw('')->assertStatus(422);
        $this->push(['products' => ['a' => $this->product('A1')]])->assertStatus(422);
        $this->assertSame(0, CatalogProduct::count());
        $this->assertSame(4, CatalogLoad::where('status', 'failed')->count());
    }

    public function test_body_over_20_mb_is_refused(): void
    {
        $body = '{"products":[],"pad":"'.str_repeat('x', 20 * 1024 * 1024).'"}';
        $this->raw($body)->assertStatus(413);
        $this->assertSame(0, CatalogLoad::count());
    }

    public function test_bad_clients_do_not_change_anything(): void
    {
        $this->push(['products' => [$this->product('A1')], 'clients' => [['price_group' => 'partner', 'company' => 'X', 'phones' => ['+998901234567']]]])->assertOk();

        $this->push(['products' => [$this->product('A1')], 'clients' => ['price_group' => 'dealer']])->assertStatus(422);
        $this->assertSame(1, CatalogClient::count());

        $r = $this->push(['products' => [$this->product('A1')], 'clients' => [
            ['price_group' => 'dealer', 'company' => 'Oila', 'inn' => 301234567, 'phones' => ['+998901234567', 'abc', 777]],
            ['price_group' => 'partner', 'company' => 'Big', 'phones' => ['901234567']],
            ['price_group' => 'end_user', 'company' => 'No', 'phones' => ['+998907777777']],
            ['price_group' => 'dealer', 'company' => '', 'phones' => ['+998907777778']],
        ]])->assertOk();
        $this->assertSame('partner', CatalogClient::where('phone', '+998901234567')->value('price_group'), 'higher group wins');
        $this->assertSame(1, CatalogClient::count());
        $this->assertCount(4, $r->json('rejected'));
    }

    public function test_ratio_counts_only_valid_products(): void
    {
        $this->push(['products' => array_map(fn ($i) => $this->product("P{$i}"), range(1, 20))])->assertOk();

        $mixed = array_map(fn ($i) => $this->product("P{$i}", $i > 9 ? ['category' => 'nope'] : []), range(1, 20));
        $this->push(['products' => $mixed])->assertStatus(422)->assertJson(['status' => 'refused']);
        $this->assertSame(20, CatalogProduct::available()->count());

        // exactly half is still accepted
        $this->push(['products' => array_map(fn ($i) => $this->product("P{$i}"), range(1, 10))])->assertOk()->assertJson(['hidden' => 10]);
    }

    public function test_sku_with_spaces_counts_as_duplicate(): void
    {
        $this->push(['products' => [$this->product('A1'), $this->product(' A1 ')]])->assertStatus(422);
        $this->push(['products' => [$this->product(' A2 ')]])->assertOk();
        $this->assertSame('A2', CatalogProduct::value('sku'));
    }

    public function test_concurrent_load_gets_409(): void
    {
        $lock = Cache::lock('catalog-import', 60);
        $this->assertTrue($lock->get());
        $this->push(['products' => [$this->product('A1')]])->assertStatus(409);
        $lock->release();
        $this->push(['products' => [$this->product('A1')]])->assertOk();
    }

    public function test_five_thousand_products_twice(): void
    {
        $items = array_map(fn ($i) => $this->product("SKU-{$i}", [
            'name' => ['uz' => "Mahsulot {$i}", 'ru' => "Товар {$i}", 'en' => "Product {$i}"],
            'params' => ['print_width_mm' => 104, 'resolution_dpi' => 203, 'connectivity' => ['usb', 'lan', 'wifi'], 'class' => 'desktop'],
        ]), range(1, 5000));
        $body = json_encode(['products' => $items], JSON_UNESCAPED_UNICODE);
        $this->assertLessThan(CatalogImporter_MAX(), strlen($body));

        $t = microtime(true);
        $this->raw($body)->assertOk()->assertJson(['accepted' => 5000]);
        $first = microtime(true) - $t;

        $items[0]['prices']['end_user'] = 1;
        $t = microtime(true);
        $this->raw(json_encode(['products' => array_slice($items, 0, 4990)], JSON_UNESCAPED_UNICODE))->assertOk()->assertJson(['accepted' => 4990, 'hidden' => 10]);
        $second = microtime(true) - $t;

        $this->assertSame(1, CatalogProduct::where('sku', 'SKU-1')->first()->prices['end_user']);
        $this->assertSame(4990, CatalogProduct::available()->count());
        fwrite(STDERR, sprintf("\n[catalog 5000] first %.2fs, second %.2fs, body %.1f MB, peak mem %d MB\n", $first, $second, strlen($body) / 1048576, memory_get_peak_usage(true) / 1048576));
        $this->assertLessThan(30, $first + $second);

        // Admin page with 4990 products: paginated by 50
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('page=2', false);
        $this->actingAs($admin)->get(route('admin.catalog.index', ['page' => 101]))->assertOk()->assertSee('No products');
        $this->actingAs($admin)->get(route('admin.catalog.index', ['hidden' => 1]))->assertOk()->assertSee('SKU-4991')->assertDontSee('SKU-17<', false);
    }

    public function test_admin_access_rules(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->get(route('admin.catalog.index'))->assertRedirect();
        $this->actingAs($user)->get(route('admin.catalog.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->post(route('admin.catalog.token'))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->put(route('admin.catalog.update'), ['source' => 'push', 'max_age_hours' => 1])->assertRedirect(route('admin.login'));
        $this->actingAs($user)->post(route('admin.catalog.pull'))->assertRedirect(route('admin.login'));
        $this->assertNull(CatalogSetting::current()->pull_url);
    }

    public function test_admin_search_category_and_hidden_filters(): void
    {
        $this->push(['products' => [
            $this->product('LP-1', ['brand' => 'Zebra', 'model' => 'ZD421t']),
            $this->product('SC-1', ['brand' => 'Honeywell', 'model' => '1470g', 'category' => 'scanner']),
            $this->product('OLD-1', ['brand' => 'Godex', 'model' => 'G500', 'active' => false]),
            $this->product('PCT_1', ['brand' => 'Xprinter', 'model' => '50%off']),
        ]])->assertOk();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.catalog.index', ['q' => 'honey']))->assertOk()->assertSee('1470g')->assertDontSee('ZD421t');
        $this->actingAs($admin)->get(route('admin.catalog.index', ['category' => 'scanner']))->assertOk()->assertSee('1470g')->assertDontSee('ZD421t');
        $this->actingAs($admin)->get(route('admin.catalog.index', ['category' => 'bogus']))->assertOk()->assertSee('ZD421t')->assertSee('1470g');
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertDontSee('G500')->assertSee('1 hidden');
        $this->actingAs($admin)->get(route('admin.catalog.index', ['hidden' => 1]))->assertOk()->assertSee('G500')->assertSee('Inactive in 1C')->assertDontSee('ZD421t');
        $this->actingAs($admin)->get(route('admin.catalog.index', ['category' => ['x']]))->assertOk();
    }

    /** DEFECT: ?q[]=x is a 500 (CatalogController.php:19 casts an array to string). */
    public function test_array_query_does_not_crash(): void
    {
        $this->actingAs($this->admin())->get(route('admin.catalog.index', ['q' => ['x']]))->assertOk();
    }

    /** DEFECT: on SQLite addcslashes() needs ESCAPE '\' — a search containing % or _ finds nothing. */
    public function test_search_with_like_wildcards(): void
    {
        $this->push(['products' => [$this->product('LP-1', ['model' => 'ZD421t']), $this->product('RIB_WAX', ['model' => '50%off'])]])->assertOk();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.catalog.index', ['q' => 'RIB_WAX']))->assertOk()->assertSee('50%off');
        $this->actingAs($admin)->get(route('admin.catalog.index', ['q' => '%']))->assertOk()->assertSee('50%off')->assertDontSee('ZD421t');
    }

    public function test_settings_validation_keeps_input_but_not_the_password(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.catalog.index'))->put(route('admin.catalog.update'), [
            'source' => 'pull', 'pull_url' => 'ftp://x', 'pull_username' => 'bdb_site', 'pull_password' => 'TopSecret9', 'max_age_hours' => 24,
        ])->assertRedirect(route('admin.catalog.index'))->assertSessionHasErrors('pull_url');

        $this->assertSame('bdb_site', session()->getOldInput('pull_username'));
        $this->assertNull(session()->getOldInput('pull_password'), 'password must not be flashed');

        $html = $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="bdb_site"', $html);
        $this->assertStringNotContainsString('TopSecret9', $html);

        // pull without any password
        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'https://1c.example.uz/x', 'pull_username' => 'u', 'max_age_hours' => 24])
            ->assertSessionHasErrors('pull_password');
        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'nope', 'max_age_hours' => 0])->assertSessionHasErrors(['source', 'max_age_hours']);
    }

    public function test_saved_password_and_token_are_never_shown(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.catalog.update'), [
            'source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/bdb/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'TopSecret9', 'max_age_hours' => 24,
        ])->assertSessionHasNoErrors();
        $this->assertNotSame('TopSecret9', CatalogSetting::query()->toBase()->value('pull_password'), 'stored encrypted');

        $html = $this->actingAs($admin)->get(route('admin.catalog.index'))->getContent();
        $this->assertStringNotContainsString('TopSecret9', $html);
        $this->assertStringNotContainsString($this->token, $html);
        $this->assertStringContainsString('saved — leave empty to keep', $html);
        $this->assertStringContainsString('Load now from 1C', $html);
    }

    public function test_switching_to_push_creates_a_token_shown_once(): void
    {
        $settings = CatalogSetting::current();
        $settings->forceFill(['token_hash' => null, 'token_hint' => null])->save();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'push', 'max_age_hours' => 24])->assertSessionHas('catalog_token');
        $token = session('catalog_token');
        $this->assertStringStartsWith('bdb_cat_', $token);
        $this->assertTrue(CatalogSetting::current()->tokenMatches($token));

        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee($token);
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertDontSee($token)->assertSee('…'.substr($token, -4));

        // Saving again does not rotate it
        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'push', 'max_age_hours' => 24])->assertSessionMissing('catalog_token');
        $this->assertTrue(CatalogSetting::current()->tokenMatches($token));

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/catalog', ['products' => [$this->product('A1')]])->assertOk();
    }

    public function test_load_now_in_pull_mode(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertSessionHas('error');

        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/bdb/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'p@ss']);
        Http::fake(['1c.example.uz/*' => Http::response(['products' => [$this->product('A1'), $this->product('A2', ['category' => 'x'])]])]);

        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertRedirect(route('admin.catalog.index'))
            ->assertSessionHas('success', 'Loaded: 1 products, 1 rejected.');
        $load = CatalogLoad::latest('id')->first();
        $this->assertSame(['pull', 'manual', 'ok'], [$load->source, $load->trigger, $load->status]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r->url() === 'https://1c.example.uz/hs/bdb/catalog');

        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Rejected items')->assertSee('A2');

        // push is refused in pull mode
        $this->push(['products' => [$this->product('A9')]])->assertStatus(409);
    }

    public function test_pull_does_not_follow_redirects_and_handles_errors(): void
    {
        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/bdb/catalog', 'pull_username' => 'bdb_site', 'pull_password' => 'p']);
        Http::fake([
            '1c.example.uz/*' => Http::sequence()
                ->push('', 302, ['Location' => 'https://evil.example/steal'])
                ->push('<html>Login</html>', 200)
                ->push(['error' => 'Ошибка формирования'], 500)
                ->pushFailedConnection(),
            'evil.example/*' => Http::response(['products' => []]),
        ]);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertSessionHas('error');
        $this->assertStringContainsString('HTTP 302', CatalogLoad::latest('id')->first()->error);
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertSessionHas('error');
        $this->assertStringContainsString('did not return JSON', CatalogLoad::latest('id')->first()->error);
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertSessionHas('error');
        $this->assertStringContainsString('Ошибка формирования', CatalogLoad::latest('id')->first()->error);
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertSessionHas('error');
        $this->assertStringContainsString('Could not reach 1C', CatalogLoad::latest('id')->first()->error);

        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.example'));
    }

    public function test_push_problem_alerts_once_per_hour(): void
    {
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.alert_chat_id' => '-100']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->push(['products' => []])->assertStatus(422);
        $this->push(['products' => []])->assertStatus(422);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'] ?? '', 'Product catalog') && ! str_contains($r['text'] ?? '', $this->token));
    }

    public function test_task_is_scheduled_and_prunes(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('catalog:pull')->assertSuccessful();

        CatalogLoad::create(['source' => 'push', 'trigger' => 'push', 'status' => 'ok']);
        $this->travel(91)->days();
        CatalogLoad::create(['source' => 'push', 'trigger' => 'push', 'status' => 'ok']);
        $this->artisan('model:prune', ['--model' => [CatalogLoad::class]])->assertSuccessful();
        $this->assertSame(1, CatalogLoad::count());
    }

    public function test_robots_disallows_api(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /api/');
    }
}

function CatalogImporter_MAX(): int
{
    return CatalogImporter::MAX_BYTES;
}
