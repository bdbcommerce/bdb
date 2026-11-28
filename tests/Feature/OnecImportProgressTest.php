<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\OnecItem;
use App\Models\OnecPerson;
use App\Models\User;
use App\Services\Companies\CompanyLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Load items / companies from 1C" with the progress window: start asks 1C once, step saves the list in parts. */
class OnecImportProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.onec' => ['url' => 'http://1c.test/hs', 'login' => 'bot', 'password' => 'secret', 'items_path' => '/crm/item', 'companies_path' => '/crm/company', 'timeout' => 5]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function fakeItems(int $n): void
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['id' => 'guid-'.$i, 'code' => $i, 'name' => 'Item '.$i, 'unit' => 'шт'];
        }
        $rows[] = ['id' => 'guid-bad', 'name' => 'No code'];
        Http::fake(['1c.test/hs/crm/item' => Http::response(['data' => $rows])]);
    }

    public function test_items_load_in_steps_with_counts(): void
    {
        $this->fakeItems(120);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.companies.import-start', 'items'))->assertOk()->assertExactJson(['total' => 121, 'step' => CompanyLookup::IMPORT_STEP]);
        $this->assertSame(0, OnecItem::count());

        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 0])->assertOk()
            ->assertExactJson(['total' => 121, 'done' => 100, 'counts' => ['saved' => 100, 'skipped' => 0]]);
        $this->assertSame(100, OnecItem::count());
        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 100])
            ->assertExactJson(['total' => 121, 'done' => 121, 'counts' => ['saved' => 20, 'skipped' => 1]]);
        $this->assertSame(120, OnecItem::count());

        // 1C was asked once; a step past the end saves nothing.
        Http::assertSentCount(1);
        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 500])->assertJson(['done' => 121, 'counts' => ['saved' => 0]]);
    }

    public function test_companies_load_in_steps(): void
    {
        Http::fake(['1c.test/hs/crm/company' => Http::response(['data' => [
            ['id' => 'c1', 'code' => 10, 'name' => 'Korzinka MCHJ', 'stir' => '301234567'],
            ['id' => 'c2', 'code' => 11, 'name' => 'Ali Valiyev', 'stir' => '', 'phone' => '901234567'],
        ]])]);
        Client::create(['name' => 'Old name', 'inn' => '301234567', 'status' => 'active']);
        $admin = $this->admin();

        $total = $this->actingAs($admin)->postJson(route('admin.companies.import-start', 'companies'))->assertOk()->json('total');
        $counts = $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'companies'), ['offset' => 0])->assertOk()->assertJson(['done' => $total])->json('counts');

        $this->assertSame($total, array_sum($counts));
        $this->assertSame(1, $counts['updated']);
        $this->assertSame($counts['private'], OnecPerson::count());
    }

    public function test_step_without_a_started_load_and_bad_input_are_refused(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 0])->assertStatus(422)->assertJson(['message' => 'The list from 1C is no longer kept. Start the load again.']);
        Cache::put('onec:items', [['id' => 'a', 'code' => 1, 'name' => 'A']], 60);
        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => -1])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'))->assertStatus(422);
        $this->actingAs($admin)->postJson('/admin/clients/import/users/start')->assertNotFound();
        $this->assertSame(0, OnecItem::count());
    }

    public function test_1c_errors_come_back_as_a_message_without_the_login(): void
    {
        Http::fake(['1c.test/*' => Http::response('boom', 500)]);
        $this->actingAs($this->admin())->postJson(route('admin.companies.import-start', 'items'))->assertStatus(422)
            ->assertJson(['message' => '1C items: HTTP 500'])->assertDontSee('secret');

        config(['services.onec.url' => null]);
        $this->actingAs($this->admin())->postJson(route('admin.companies.import-start', 'companies'))->assertStatus(422)->assertJson(['message' => '1C is not set (ONEC_BASE_URL / ONEC_COMPANIES_PATH).']);
    }

    /** Two 1C items with one code: the second is left out, the rest still loads, and no SQL reaches the admin. */
    public function test_a_row_the_database_refuses_is_skipped(): void
    {
        Http::fake(['1c.test/hs/crm/item' => Http::response(['data' => [
            ['id' => 'g1', 'code' => 7, 'name' => 'First'], ['id' => 'g2', 'code' => 7, 'name' => 'Second'], ['id' => 'g3', 'code' => 8, 'name' => 'Third', 'extra' => str_repeat('x', 5000)],
        ]])]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.companies.import-start', 'items'))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 0])->assertOk()
            ->assertExactJson(['total' => 3, 'done' => 3, 'counts' => ['saved' => 2, 'skipped' => 1]])->assertDontSee('SQLSTATE');

        $this->assertSame(['First', 'Third'], OnecItem::orderBy('code')->pluck('name')->all());
        // Only the fields that are saved are kept in the cache.
        $this->assertArrayNotHasKey('extra', Cache::get('onec:items')[2]);
    }

    /** An empty answer from 1C is not remembered: the next lookup asks 1C again. */
    public function test_an_empty_company_list_is_not_kept(): void
    {
        Http::fake(['1c.test/hs/crm/company' => Http::sequence()->push('', 404)->push(['data' => [['id' => 'c1', 'code' => 10, 'name' => 'Korzinka MCHJ', 'stir' => '301234567']]])]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.companies.import-start', 'companies'))->assertOk()->assertJson(['total' => 0]);
        $this->assertNull(Cache::get('onec:companies'));
        $this->assertCount(1, app(CompanyLookup::class)->oneCCompanies());

        config(['services.onec.companies_path' => null]);
        $this->actingAs($admin)->postJson(route('admin.companies.import-start', 'companies'))->assertStatus(422)->assertJson(['message' => '1C is not set (ONEC_BASE_URL / ONEC_COMPANIES_PATH).']);
    }

    public function test_only_admins_can_load(): void
    {
        $this->fakeItems(3);
        $this->postJson(route('admin.companies.import-start', 'items'))->assertStatus(403);
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->postJson(route('admin.companies.import-start', 'items'))->assertStatus(403);
        Cache::put('onec:items', [['id' => 'a', 'code' => 1, 'name' => 'A']], 60);
        $this->actingAs($user)->postJson(route('admin.companies.import-step', 'items'), ['offset' => 0])->assertStatus(403);
        $this->assertSame(0, OnecItem::count());
        Http::assertNothingSent();
    }

    public function test_pages_carry_the_progress_window_and_the_plain_form_still_works(): void
    {
        $this->fakeItems(2);
        $admin = $this->admin();
        // Items are loaded from the Product catalog only, companies from Companies.
        foreach (['admin.products.index' => 'items', 'admin.clients.index' => 'companies'] as $page => $what) {
            $this->actingAs($admin)->get(route($page))->assertOk()->assertSee('data-onec-import="'.$what.'"', false)->assertSee('role="progressbar"', false)->assertSee('x-data="onecImport()"', false)->assertSee('function onecImport()', false);
        }
        $this->actingAs($admin)->get(route('admin.clients.index'))->assertDontSee('data-onec-import="items"', false);
        $this->actingAs($admin)->post(route('admin.companies.import-items'))->assertSessionHas('success', 'Items from 1C: 2 saved, 1 skipped (no id, code or name).');
    }
}
