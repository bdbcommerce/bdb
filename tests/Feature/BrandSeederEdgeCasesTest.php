<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Database\Seeders\BrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** QA: BrandSeeder edge cases, bundled logo files and the public brand grids. */
class BrandSeederEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function brand(string $name, array $attrs = []): Brand
    {
        return Brand::create($attrs + [
            'name' => ['uz' => $name, 'ru' => $name, 'en' => $name],
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->seed(BrandSeeder::class);
        $before = Brand::orderBy('id')->get()->map->only(['id', 'logo', 'website', 'sort_order', 'is_active'])->all();
        $desc = Brand::orderBy('id')->get()->map(fn ($b) => $b->getTranslations('description'))->all();

        $this->seed(BrandSeeder::class);
        $this->seed(BrandSeeder::class);

        $this->assertSame(19, Brand::count());
        $this->assertSame($before, Brand::orderBy('id')->get()->map->only(['id', 'logo', 'website', 'sort_order', 'is_active'])->all());
        $this->assertSame($desc, Brand::orderBy('id')->get()->map(fn ($b) => $b->getTranslations('description'))->all());
        $this->assertCount(18, Storage::disk('public')->files('brands'));
    }

    public function test_deleted_logo_file_is_restored_and_missing_bundled_logo_clears_broken_path(): void
    {
        $this->seed(BrandSeeder::class);
        Storage::disk('public')->delete('brands/zebra.png');
        Brand::where('name->en', 'Asianwell')->update(['logo' => 'brands/gone.png']);

        $this->seed(BrandSeeder::class);

        Storage::disk('public')->assertExists('brands/zebra.png');
        $this->assertSame('brands/zebra.png', Brand::where('name->en', 'Zebra Technologies')->value('logo'));
        $this->assertNull(Brand::where('name->en', 'Asianwell')->value('logo'), 'a broken path with no bundled logo is cleared');
    }

    public function test_admin_logo_for_brand_without_bundled_logo_is_kept(): void
    {
        Storage::disk('public')->put('brands/hprt-own.png', 'x');
        $this->brand('hprt', ['logo' => 'brands/hprt-own.png']);
        $this->brand('ASIANWELL', ['website' => 'https://asianwell.example']);

        $this->seed(BrandSeeder::class);

        $this->assertSame(19, Brand::count());
        $this->assertSame('brands/hprt-own.png', Brand::where('name->en', 'hprt')->value('logo'));
        $this->assertSame('hprt', Brand::where('name->en', 'hprt')->sole()->getTranslation('name', 'en'), 'name is not changed');
        $this->assertSame('https://asianwell.example', Brand::where('name->en', 'ASIANWELL')->value('website'));
    }

    public function test_match_in_another_locale_with_whitespace_and_cyrillic_names(): void
    {
        $this->brand('x', ['name' => ['uz' => '  GODEX ', 'ru' => 'Годекс', 'en' => 'Годекс']]);

        $this->seed(BrandSeeder::class);

        $this->assertSame(19, Brand::count());
        $godex = Brand::where('name->ru', 'Годекс')->sole();
        $this->assertSame('https://www.godexintl.com', $godex->website);
        $this->assertSame('brands/godex.png', $godex->logo);
    }

    public function test_inactive_existing_brand_stays_inactive_and_keeps_its_position(): void
    {
        $this->brand('Lenovo', ['is_active' => false, 'sort_order' => 99]);

        $this->seed(BrandSeeder::class);

        $lenovo = Brand::where('name->en', 'Lenovo')->sole();
        $this->assertFalse($lenovo->is_active);
        $this->assertSame(99, (int) $lenovo->sort_order);
        $this->assertSame(18, Brand::active()->count());
        $this->assertGreaterThan(99, (int) Brand::where('name->en', 'Zebra Technologies')->value('sort_order'), 'new brands go after existing ones');
    }

    public function test_duplicate_existing_brands_only_the_first_is_updated_nothing_new_created(): void
    {
        $a = $this->brand('Rongta');
        $b = $this->brand('rongta');

        $this->seed(BrandSeeder::class);

        $this->assertSame(19, Brand::count() - 1);
        $this->assertSame('brands/rongta.png', $a->fresh()->logo);
        $this->assertNull($b->fresh()->logo);
    }

    public function test_renamed_brand_is_not_recognised_and_a_second_copy_is_created(): void
    {
        // Documents current behaviour: matching is by exact (case-insensitive) name only.
        $this->brand('Zebra');

        $this->seed(BrandSeeder::class);

        $this->assertSame(20, Brand::count());
    }

    public function test_bundled_logos_are_valid_transparent_pngs_of_sensible_size(): void
    {
        $files = glob(database_path('seeders/data/brand-logos/*.png'));
        $this->assertCount(18, $files);
        foreach ($files as $file) {
            $name = basename($file);
            $info = getimagesize($file);
            $this->assertSame(IMAGETYPE_PNG, $info[2], $name);
            $this->assertSame(6, ord(file_get_contents($file)[25]), "{$name}: RGBA");
            $this->assertLessThanOrEqual(1200, $info[0], $name);
            $this->assertLessThanOrEqual(400, $info[1], $name);
            $this->assertGreaterThanOrEqual(150, $info[0], $name);
            $this->assertLessThan(100 * 1024, filesize($file), $name);

            $im = imagecreatefrompng($file);
            $transparent = 0;
            $total = 0;
            for ($y = 0; $y < $info[1]; $y += 4) {
                for ($x = 0; $x < $info[0]; $x += 4) {
                    $total++;
                    if ((imagecolorat($im, $x, $y) >> 24 & 0x7F) === 127) {
                        $transparent++;
                    }
                }
            }
            $this->assertGreaterThan(0.2, $transparent / $total, "{$name}: has a transparent background");
        }
    }

    public function test_uzbek_descriptions_use_modifier_letters_not_ascii_apostrophes(): void
    {
        $this->seed(BrandSeeder::class);

        foreach (Brand::all() as $brand) {
            $uz = $brand->getTranslation('description', 'uz', false);
            $this->assertDoesNotMatchRegularExpression("/['`‘’]/u", $uz, $brand->getTranslation('name', 'en'));
            $this->assertDoesNotMatchRegularExpression('/[gG]ʼ|[oO]ʼ/u', $uz, 'oʻ / gʻ use U+02BB');
        }
    }

    public function test_public_pages_in_all_locales_show_19_brands_and_12_on_home(): void
    {
        $this->seed(BrandSeeder::class);

        foreach (['uz', 'ru', 'en'] as $locale) {
            $html = $this->get("/{$locale}/brands")->assertOk()->getContent();
            $this->assertSame(18, preg_match_all('#<img src="[^"]*/brands/[a-z-]+\.png"#', $html), "{$locale} brands logos");
            foreach (['Asianwell'] as $name) {
                $this->assertMatchesRegularExpression('#<span class="text-lg font-semibold[^"]*text-gray-600[^"]*">\s*'.$name.'\s*</span>#', $html, "{$locale} {$name} text tile");
            }
            $this->assertStringContainsString(e(Brand::where('name->en', 'Lenovo')->sole()->getTranslation('description', $locale)), $html);
            $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_.]+\s*</', $html, "{$locale}: raw translation key");

            $home = $this->get("/{$locale}")->assertOk()->getContent();
            preg_match('#<section id="brands".*?</section>#s', $home, $m);
            $this->assertSame(12, preg_match_all('#<li class="group flex h-28[^"]*shadow-\[0_0_0_1px_var\(--color-gray-200\)\]#', $m[0]), "{$locale} home tiles");
            $this->assertStringContainsString('bg-white">', preg_replace('/\s+/', ' ', $m[0]) ?: '');
            $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_.]+\s*</', $home, "{$locale}: raw translation key");
        }
    }

    public function test_admin_brand_form_shows_new_hint(): void
    {
        $this->seed(BrandSeeder::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $brand = Brand::first();

        $this->actingAs($admin)->get(route('admin.brands.edit', $brand))
            ->assertOk()->assertSee('dark grey on a white tile');
    }
}
