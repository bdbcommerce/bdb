<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** brands:import-logos reads brand.js + img/ and sets the logos; the site shows them white on dark tiles. */
class ImportBrandLogosTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    /** 1×1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->dir = storage_path('framework/testing/brand-'.uniqid());
        File::ensureDirectoryExists($this->dir.'/img');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function brand(string $en, array $more = []): Brand
    {
        return Brand::create(['name' => ['en' => $en] + $more, 'is_active' => true, 'sort_order' => 1]);
    }

    private function list(string $js): void
    {
        File::put($this->dir.'/brand.js', $js);
    }

    public function test_imports_logos_matched_by_name_in_any_language(): void
    {
        $zebra = $this->brand('Zebra Technologies');
        $godex = $this->brand('Godex', ['ru' => 'Годекс']);
        File::put($this->dir.'/img/zebra.png', base64_decode(self::PNG));
        File::put($this->dir.'/img/godex.png', base64_decode(self::PNG));
        $this->list(<<<'JS'
            // comment with [brackets] and https://example.com
            export default [
                { name: "zebra technologies", logo: "zebra.png" },
                { "name": "Годекс", "logo": "godex.png" },
                { "name": "Urovo", "logo": "" },
            ];
            JS);

        $this->artisan('brands:import-logos', ['--path' => $this->dir])->assertSuccessful();

        foreach ([$zebra, $godex] as $brand) {
            $logo = $brand->fresh()->logo;
            $this->assertMatchesRegularExpression('#^brands/[A-Za-z0-9]{40}\.png$#', $logo);
            Storage::disk('public')->assertExists($logo);
        }

        // Same file again: nothing changes.
        $before = $zebra->fresh()->logo;
        $this->artisan('brands:import-logos', ['--path' => $this->dir])->assertSuccessful();
        $this->assertSame($before, $zebra->fresh()->logo);
    }

    public function test_replacing_a_logo_deletes_the_old_file(): void
    {
        Storage::disk('public')->put('brands/old.png', 'old');
        $brand = $this->brand('Honeywell');
        $brand->update(['logo' => 'brands/old.png']);
        File::put($this->dir.'/img/h.png', base64_decode(self::PNG));
        $this->list('export default [{ "name": "Honeywell", "logo": "h.png" }];');

        $this->artisan('brands:import-logos', ['--path' => $this->dir])->assertSuccessful();

        Storage::disk('public')->assertMissing('brands/old.png');
        $this->assertNotSame('brands/old.png', $brand->fresh()->logo);
    }

    public function test_dry_run_and_bad_files_change_nothing(): void
    {
        $brand = $this->brand('Datalogic');
        File::put($this->dir.'/img/fake.png', '<?php echo 1;');
        File::put($this->dir.'/img/ok.png', base64_decode(self::PNG));
        File::put(dirname($this->dir).'/outside.png', base64_decode(self::PNG));

        $this->list('export default [{ "name": "Datalogic", "logo": "fake.png" }, { "name": "Nobody", "logo": "ok.png" }, { "name": "Datalogic", "logo": "../outside.png" }];');
        $this->artisan('brands:import-logos', ['--path' => $this->dir])->assertFailed();
        $this->assertNull($brand->fresh()->logo);

        $this->list('export default [{ "name": "Datalogic", "logo": "ok.png" }];');
        $this->artisan('brands:import-logos', ['--path' => $this->dir, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($brand->fresh()->logo);
        $this->assertSame([], Storage::disk('public')->allFiles());

        File::delete(dirname($this->dir).'/outside.png');
    }

    public function test_unreadable_list_fails(): void
    {
        $this->list('export default { broken');
        $this->artisan('brands:import-logos', ['--path' => $this->dir])->assertFailed();
    }

    public function test_logos_render_white_and_turn_coloured_on_hover(): void
    {
        $this->brand('Zebra Technologies')->update(['logo' => 'brands/z.png']);

        foreach (['/en', '/en/brands'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<img src="[^"]*brands/z\.png"[^>]*class="[^"]*brightness-0 invert[^"]*group-hover:brightness-100 group-hover:invert-0#s', $html, $url);
            $this->assertStringContainsString('bg-gray-900', $html, $url);
        }
    }
}
