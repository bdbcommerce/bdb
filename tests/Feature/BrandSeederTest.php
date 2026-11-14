<?php

namespace Tests\Feature;

use App\Models\Brand;
use Database\Seeders\BrandSeeder;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** BrandSeeder: 19 real brands with logo, website and uz/ru/en description; safe on existing data. */
class BrandSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_fresh_database_gets_all_brands_with_logos(): void
    {
        $this->seed(BrandSeeder::class);

        $this->assertSame(19, Brand::count());
        foreach (Brand::all() as $brand) {
            $name = $brand->getTranslation('name', 'en');
            $this->assertTrue($brand->is_active, $name);
            // Asianwell: no own website or logo found.
            if ($name !== 'Asianwell') {
                $this->assertMatchesRegularExpression('#^https://#', $brand->website, $name);
            }
            if ($name !== 'Asianwell') {
                Storage::disk('public')->assertExists($brand->logo);
                $this->assertNotFalse(@imagecreatefromstring(Storage::disk('public')->get($brand->logo)), $name.': logo is an image');
            } else {
                $this->assertNull($brand->logo, $name);
            }
            foreach (['uz', 'ru', 'en'] as $locale) {
                $this->assertNotSame('', $brand->getTranslation('description', $locale, false), "{$name} {$locale}");
                $this->assertSame($name, $brand->getTranslation('name', $locale, false));
            }
        }
        $this->assertSame(Brand::count(), Brand::pluck('sort_order')->unique()->count(), 'distinct sort order');
    }

    public function test_existing_brands_are_updated_not_duplicated_and_admin_text_is_kept(): void
    {
        $this->seed(DemoContentSeeder::class); // Zebra, Honeywell, Datalogic, TSC Printronix, Godex, Urovo
        $honeywell = Brand::where('name->en', 'Honeywell')->sole();
        $honeywell->update(['description' => ['uz' => '', 'ru' => 'Своё описание', 'en' => 'Own text']]);
        Storage::disk('public')->put('brands/own.png', 'x');
        $zebra = Brand::where('name->en', 'Zebra Technologies')->sole();
        $zebra->update(['name' => ['en' => 'zebra technologies', 'ru' => 'Зебра', 'uz' => 'Zebra Technologies'], 'logo' => 'brands/own.png']);

        $this->seed(BrandSeeder::class);
        $this->seed(BrandSeeder::class); // re-run: nothing duplicated

        $this->assertSame(19, Brand::count());
        $honeywell->refresh();
        $this->assertSame('https://automation.honeywell.com', $honeywell->website);
        $this->assertSame('Own text', $honeywell->getTranslation('description', 'en'));
        $this->assertSame('Своё описание', $honeywell->getTranslation('description', 'ru'));
        $this->assertStringContainsString('skanerlari', $honeywell->getTranslation('description', 'uz'), 'empty language filled');
        $this->assertSame('brands/honeywell.png', $honeywell->logo);
        $this->assertSame('brands/own.png', $zebra->fresh()->logo, 'an existing logo is kept');
    }

    public function test_a_logo_placed_by_the_seeder_is_refreshed_with_the_bundled_file(): void
    {
        $this->seed(BrandSeeder::class);
        Storage::disk('public')->put('brands/urovo.png', 'old file');

        $this->seed(BrandSeeder::class);

        $this->assertSame(file_get_contents(database_path('seeders/data/brand-logos/urovo.png')), Storage::disk('public')->get('brands/urovo.png'));
    }

    public function test_brands_page_shows_the_seeded_logos(): void
    {
        $this->seed(BrandSeeder::class);

        $html = $this->get('/ru/brands')->assertOk()->getContent();
        foreach (['zebra', 'gainscha', 'henex'] as $slug) {
            $this->assertStringContainsString("brands/{$slug}.png", $html);
        }
        // The website link goes through the click counter, which redirects to the brand's site.
        $henex = Brand::where('name->en', 'HENEX')->sole();
        $this->assertStringContainsString(route('go.brand', [$henex, 'website', 'l' => 'ru']), $html);
        $this->get(route('go.brand', [$henex, 'website']))->assertRedirect('https://www.henex.cn');

        // Dark grey logo on white; the real colours on hover (home and brands page).
        foreach (['/ru', '/ru/brands'] as $url) {
            $this->assertMatchesRegularExpression('#<img src="[^"]*brands/zebra\.png"[^>]*class="[^"]*brightness-0 opacity-70[^"]*group-hover:brightness-100 group-hover:opacity-100#s', $this->get($url)->getContent(), $url);
        }
    }
}
