<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Brand → online shop link filtered by the brand (e.g. mylabel.uz). */
class BrandShopUrlTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://mylabel.uz/catalog?brand=zebra';

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function form(array $more = []): array
    {
        return $more + ['name_uz' => 'Zebra', 'name_ru' => 'Zebra', 'name_en' => 'Zebra', 'is_active' => '1'];
    }

    public function test_admin_saves_and_clears_the_shop_link(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.brands.store'), $this->form(['shop_url' => '  '.self::SHOP.' ']))
            ->assertRedirect(route('admin.brands.index'));
        $brand = Brand::sole();
        $this->assertSame(self::SHOP, $brand->shop_url);

        $this->actingAs($admin)->get(route('admin.brands.edit', $brand))->assertOk()
            ->assertSee('name="shop_url"', false)->assertSee(self::SHOP);

        $this->actingAs($admin)->put(route('admin.brands.update', $brand), $this->form(['shop_url' => '']))->assertRedirect();
        $this->assertNull($brand->fresh()->shop_url);
    }

    public function test_bad_shop_links_are_rejected(): void
    {
        $admin = $this->admin();
        foreach (['javascript:alert(1)', 'mylabel.uz', 'ftp://mylabel.uz', 'https://localhost/x', 'https://mylabel.uz/a b', 'https://'.str_repeat('a', 500).'.uz'] as $bad) {
            $this->actingAs($admin)->from(route('admin.brands.create'))
                ->post(route('admin.brands.store'), $this->form(['shop_url' => $bad]))
                ->assertSessionHasErrors('shop_url');
        }
        $this->assertSame(0, Brand::count());
    }

    public function test_brands_page_shows_buy_online_with_utm_in_every_language(): void
    {
        Brand::create(['name' => ['en' => 'Zebra', 'ru' => 'Zebra', 'uz' => 'Zebra'], 'shop_url' => self::SHOP, 'is_active' => true]);
        Brand::create(['name' => ['en' => 'Godex', 'ru' => 'Godex', 'uz' => 'Godex'], 'is_active' => true]);

        $labels = ['en' => 'Buy online', 'ru' => 'Купить онлайн', 'uz' => 'Onlayn xarid qilish'];
        foreach ($labels as $locale => $label) {
            $html = $this->get(route('site.brands', $locale))->assertOk()->getContent();
            $zebra = Brand::where('name->en', 'Zebra')->sole();
            $this->assertStringContainsString('href="'.route('go.brand', [$zebra, 'shop', 'l' => $locale]).'"', $html, $locale);
            $this->assertStringContainsString($label, $html, $locale);
            $this->assertSame(1, substr_count($html, '/shop?l='), $locale.': one button, only for the brand with a link');
        }
        // The counter redirects to the shop link with the UTM tags.
        $this->get(route('go.brand', [Brand::where('name->en', 'Zebra')->sole(), 'shop']))
            ->assertRedirect('https://mylabel.uz/catalog?brand=zebra&utm_source=bdb_site&utm_medium=referral&utm_campaign=brand_card');
    }

    public function test_ai_knowledge_has_the_shop_link(): void
    {
        Http::fake();
        Brand::create(['name' => ['en' => 'Zebra'], 'shop_url' => self::SHOP, 'is_active' => true]);

        $prompt = app(ChatAssistant::class)->promptPreview('en', 'web');
        $this->assertStringContainsString('Buy online: https://mylabel.uz/catalog?brand=zebra&utm_source=bdb_site&utm_medium=referral&utm_campaign=ai_assistant', $prompt);
    }
}
