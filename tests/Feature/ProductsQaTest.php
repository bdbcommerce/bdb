<?php

namespace Tests\Feature;

use App\Models\OnecItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA additions for Conversations → Products: access, paging, search, the batch lock, secrets, the 1C load button. */
class ProductsQaTest extends TestCase
{
    use RefreshDatabase;

    private const AI = ['brand' => 'Xprinter', 'model' => 'XP-Q807K', 'category' => 'receipt_printer', 'summary' => 'Чековый принтер.',
        'specs' => 'Ширина: 80 мм', 'compatible_with' => '', 'keywords' => 'chek printer'];

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-key-SECRET123']);
        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Aziz']);
    }

    private function item(array $extra = []): OnecItem
    {
        $this->n++;

        return OnecItem::create($extra + ['onec_id' => 'id-'.$this->n, 'code' => 700 + $this->n, 'name' => sprintf('Item %03d', $this->n), 'unit' => 'шт']);
    }

    private function fakeAi(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(self::AI)]]]]]])]);
    }

    public function test_every_product_route_refuses_guests_and_non_admins(): void
    {
        Http::fake();
        $item = $this->item();
        $user = User::factory()->create(['is_admin' => false]);
        $routes = [['get', route('admin.products.index')], ['get', route('admin.products.edit', $item)], ['put', route('admin.products.update', $item)],
            ['post', route('admin.products.ai-fill', $item)], ['post', route('admin.products.ai-fill-batch')]];

        foreach ($routes as [$verb, $url]) {
            $this->{$verb}($url)->assertRedirect(route('admin.login'));
        }
        foreach ($routes as [$verb, $url]) {
            $this->actingAs($user)->{$verb}($url, ['brand' => 'X'])->assertRedirect(route('admin.login'));
        }
        Http::assertNothingSent();
        $this->assertSame([null, null], [$item->fresh()->brand, $item->fresh()->info_status]);
    }

    public function test_unknown_product_is_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/products/999999/edit')->assertNotFound();
        $this->actingAs($admin)->put('/admin/products/999999', ['brand' => 'X'])->assertNotFound();
    }

    public function test_second_page_keeps_the_search_and_filters(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->item();
        }
        $this->item(['name' => 'Zebra other', 'info_status' => 'checked']);
        $admin = $this->admin();

        $first = $this->actingAs($admin)->get(route('admin.products.index', ['q' => 'Item', 'info' => 'empty']))->assertOk()
            ->assertSee('Item 001')->assertSee('Item 050')->assertDontSee('Item 051')->assertDontSee('Zebra other');
        $this->assertMatchesRegularExpression('/products\?[^"]*info=empty[^"]*page=2/', $first->getContent());
        $this->assertMatchesRegularExpression('/products\?[^"]*q=Item[^"]*page=2/', $first->getContent());

        $this->actingAs($admin)->get(route('admin.products.index', ['q' => 'Item', 'info' => 'empty', 'page' => 2]))->assertOk()
            ->assertSee('Item 051')->assertSee('Item 060')->assertDontSee('Item 050');
        $this->actingAs($admin)->get(route('admin.products.index', ['page' => 99]))->assertOk()->assertSee('No products match');
    }

    public function test_search_by_code_cyrillic_and_odd_characters(): void
    {
        $scanner = $this->item(['code' => 285, 'name' => 'HC-3206 2D USB проводной', 'short_name' => 'Сканер штрихкода Henex']);
        $this->item(['code' => 99285, 'name' => 'Label 58x40_eco', 'barcode' => '4780001112223']);
        $admin = $this->admin();
        $see = fn (string $q) => $this->actingAs($admin)->get(route('admin.products.index', ['q' => $q]))->assertOk();

        $see('285')->assertSee('HC-3206')->assertDontSee('Label 58x40');
        $see('4780001112223')->assertSee('Label 58x40')->assertDontSee('HC-3206');
        $see('проводной')->assertSee('HC-3206')->assertDontSee('Label 58x40');
        $see('штрихкода')->assertSee('HC-3206');
        $see('_eco')->assertSee('Label 58x40')->assertDontSee('HC-3206');
        foreach (['%', '\\', "' OR 1=1 --", '<script>alert(1)</script>', str_repeat('я', 600)] as $q) {
            $see($q)->assertSee('No products match')->assertDontSee('<script>alert(1)</script>', false);
        }
        $this->assertNotNull($scanner->fresh());
    }

    public function test_invalid_save_shows_the_error_and_keeps_what_was_typed(): void
    {
        $item = $this->item(['brand' => 'Old']);
        $page = $this->actingAs($this->admin())->from(route('admin.products.edit', $item))->followingRedirects()
            ->put(route('admin.products.update', $item), ['brand' => str_repeat('x', 81), 'model' => 'KEEP-ME-123', 'ai_use' => '0']);

        $page->assertOk()->assertSee('must not be greater than 80 characters')->assertSee('KEEP-ME-123');
        $this->assertSame(['Old', null, true], [$item->fresh()->brand, $item->fresh()->model, $item->fresh()->ai_use]);
    }

    public function test_uzbek_and_cyrillic_text_is_saved_and_cleared_again(): void
    {
        $item = $this->item();
        $admin = $this->admin();
        $brand = "Ўзбек O‘zbek 'Бренд' <b>";

        $this->actingAs($admin)->followingRedirects()->put(route('admin.products.update', $item), ['brand' => $brand, 'specs' => "Ширина: 80 мм\r\nИнтерфейс: USB", 'ai_use' => '1'])
            ->assertOk()->assertSee('Saved and marked as checked.')->assertSee($brand)->assertDontSee("'Бренд' <b>", false);
        $this->assertSame([$brand, 'checked', 'Aziz'], [$item->fresh()->brand, $item->fresh()->info_status, $item->fresh()->info_by]);

        $this->actingAs($admin)->followingRedirects()->put(route('admin.products.update', $item), ['brand' => '   ', 'specs' => '', 'ai_use' => '1'])
            ->assertOk()->assertSee('Nothing is filled in yet');
        $item->refresh();
        $this->assertSame([null, null, null, null, null], [$item->brand, $item->specs, $item->info_status, $item->info_by, $item->info_at]);
    }

    public function test_batch_refuses_while_another_one_runs_and_frees_the_lock_afterwards(): void
    {
        $this->fakeAi();
        $item = $this->item();
        $admin = $this->admin();
        $lock = Cache::lock('products:ai-fill', 180);
        $this->assertTrue($lock->get());

        $this->actingAs($admin)->post(route('admin.products.ai-fill-batch'))->assertSessionHasErrors('ai');
        Http::assertNothingSent();
        $this->assertNull($item->fresh()->info_status);

        $lock->release();
        $this->actingAs($admin)->post(route('admin.products.ai-fill-batch'))->assertSessionHas('success');
        $this->assertSame('ai', $item->fresh()->info_status);
        $this->assertTrue(Cache::lock('products:ai-fill', 1)->get(), 'the lock is free after a good run');
    }

    public function test_batch_frees_the_lock_when_the_ai_fails(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('', 500)]);
        $item = $this->item();

        $this->actingAs($this->admin())->post(route('admin.products.ai-fill-batch'))->assertSessionHasErrors('ai');

        $this->assertNull($item->fresh()->info_status);
        $this->assertTrue(Cache::lock('products:ai-fill', 1)->get());
    }

    public function test_batch_takes_only_batch_products_in_name_order(): void
    {
        $this->fakeAi();
        for ($i = 0; $i < 10; $i++) {
            $this->item();
        }

        $this->actingAs($this->admin())->post(route('admin.products.ai-fill-batch'))->assertSessionHas('success', 'The AI described 8 products. 2 more are waiting.');

        Http::assertSentCount(8);
        $this->assertSame(['Item 009', 'Item 010'], OnecItem::withoutInfo()->orderBy('name')->pluck('name')->all());
        $this->assertNull(OnecItem::whereNotNull('sales_note')->first());
    }

    public function test_ai_error_never_shows_the_key(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'message' => 'API key not valid: test-key-SECRET123', 'status' => 'INVALID_ARGUMENT']], 400)]);
        $item = $this->item();

        $this->actingAs($this->admin())->from(route('admin.products.edit', $item))->followingRedirects()->post(route('admin.products.ai-fill', $item))
            ->assertOk()->assertSee('The AI did not answer')->assertDontSee('SECRET123');
    }

    public function test_load_from_1c_button_returns_to_products_and_new_items_start_empty(): void
    {
        config(['services.onec.url' => 'http://1c.test/hs', 'services.onec.items_path' => '/crm/item']);
        $old = $this->item(['onec_id' => 'abc', 'code' => 55, 'brand' => 'Xprinter', 'sales_note' => 'note', 'keywords' => 'k', 'info_status' => 'ai', 'info_by' => 'Gemini', 'ai_use' => false]);
        Http::fake(['1c.test/*' => Http::response(['data' => [['id' => 'abc', 'code' => 55, 'name' => 'New name'], ['id' => 'new', 'code' => 56, 'name' => 'Brand new']]])]);

        $this->actingAs($this->admin())->from(route('admin.products.index'))->followingRedirects()->post(route('admin.companies.import-items'))
            ->assertOk()->assertSee('Items from 1C: 2 saved.')->assertSee('Filter by information')->assertSee('Brand new');

        $old->refresh();
        $this->assertSame(['New name', 'Xprinter', 'note', 'k', 'ai', 'Gemini', false], [$old->name, $old->brand, $old->sales_note, $old->keywords, $old->info_status, $old->info_by, $old->ai_use]);
        $new = OnecItem::where('onec_id', 'new')->first();
        $this->assertSame([true, null], [$new->fresh()->ai_use, $new->info_status]);
    }
}
