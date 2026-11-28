<?php

namespace Tests\Feature;

use App\Models\CatalogSetting;
use App\Models\OnecItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Product catalog = one menu item with two tabs: the items loaded from 1C (Products) and the price list. */
class CatalogTabsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_catalog_opens_on_the_1c_items_once_they_are_loaded(): void
    {
        $admin = $this->admin();
        // Nothing loaded from 1C yet: the price list page, as before.
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Items from 1C')->assertSee('Price list');

        OnecItem::create(['onec_id' => 'g1', 'code' => 7, 'name' => 'Xprinter XP-420B']);
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertRedirect(route('admin.products.index'));
        $this->actingAs($admin)->get(route('admin.products.index'))->assertOk()->assertSee('Xprinter XP-420B')
            ->assertSee(route('admin.catalog.index', ['list' => 'prices']), false)->assertSee('Load items from 1C');

        // The price list tab and its filters stay on the catalog page.
        $this->actingAs($admin)->get(route('admin.catalog.index', ['list' => 'prices']))->assertOk()->assertSee('Last loads')->assertSee(route('admin.products.index'), false);
        $this->actingAs($admin)->get(route('admin.catalog.index', ['hidden' => 1]))->assertOk();
    }

    public function test_messages_after_saving_the_settings_are_not_lost(): void
    {
        OnecItem::create(['onec_id' => 'g1', 'code' => 7, 'name' => 'Xprinter XP-420B']);
        $admin = $this->admin();
        CatalogSetting::current();

        $this->actingAs($admin)->post(route('admin.catalog.token'))->assertRedirect(route('admin.catalog.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('New push token');
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertRedirect(route('admin.products.index'));

        $this->actingAs($admin)->from(route('admin.catalog.index'))->put(route('admin.catalog.update'), ['source' => 'nope', 'max_age_hours' => 0])->assertRedirect(route('admin.catalog.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk();
    }

    public function test_one_menu_item_for_both_lists(): void
    {
        $item = OnecItem::create(['onec_id' => 'g1', 'code' => 7, 'name' => 'Xprinter XP-420B']);
        $admin = $this->admin();

        foreach ([route('admin.products.index'), route('admin.products.edit', $item), route('admin.catalog.index', ['list' => 'prices'])] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            preg_match('/<nav class="flex-1[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
            $this->assertSame(1, preg_match_all('/aria-current="page"[^>]*>.*?Product catalog/s', $nav[0]), $url);
            $this->assertSame(0, substr_count($nav[0], '>Products<'), $url);
            $this->assertSame(0, substr_count($nav[0], route('admin.products.index').'"'), $url);
        }
    }
}
