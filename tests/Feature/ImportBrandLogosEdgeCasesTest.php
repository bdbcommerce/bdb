<?php

namespace Tests\Feature;

use App\Models\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Edge cases of brands:import-logos and the dark brand tiles in every locale. */
class ImportBrandLogosEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** 1×1 JPEG. */
    private const JPG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->dir = storage_path('framework/testing/brand-edge-'.uniqid());
        File::ensureDirectoryExists($this->dir.'/img');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function brand(array $name): Brand
    {
        return Brand::create(['name' => $name, 'is_active' => true, 'sort_order' => 1]);
    }

    private function list(string $js): void
    {
        File::put($this->dir.'/brand.js', $js);
    }

    private function run_(array $opts = [])
    {
        return $this->artisan('brands:import-logos', ['--path' => $this->dir] + $opts);
    }

    public function test_block_comments_whole_line_comments_urls_and_trailing_slash_path(): void
    {
        $b = $this->brand(['en' => 'Zebra']);
        File::put($this->dir.'/img/z.png', base64_decode(self::PNG));
        $this->list(<<<'JS'
            /* header [with] brackets */
            // another [x]
            export default [
                // { "name": "Zebra", "logo": "missing.png" },
                {
                    name : "Zebra",
                    logo : "z.png",
                },
            ]
            JS);

        $this->artisan('brands:import-logos', ['--path' => $this->dir.'/'])->assertSuccessful();
        $this->assertNotNull($b->fresh()->logo);
    }

    public function test_cyrillic_and_uzbek_names_match_case_insensitively(): void
    {
        $ru = $this->brand(['en' => 'X1', 'ru' => 'Штрих-М']);
        $uz = $this->brand(['en' => 'X2', 'uz' => 'Oʻzbek Belgi']);
        File::put($this->dir.'/img/a.png', base64_decode(self::PNG));
        File::put($this->dir.'/img/b.png', base64_decode(self::PNG));
        $this->list('[{"name":"  ШТРИХ-м ","logo":"a.png"},{"name":"OʻZBEK BELGI","logo":"b.png"}]');

        $this->run_()->assertSuccessful();
        $this->assertNotNull($ru->fresh()->logo);
        $this->assertNotNull($uz->fresh()->logo);
    }

    public function test_jpeg_named_png_is_stored_as_jpg(): void
    {
        $b = $this->brand(['en' => 'Honeywell']);
        File::put($this->dir.'/img/h.png', base64_decode(self::JPG));
        $this->list('[{"name":"Honeywell","logo":"h.png"}]');

        $this->run_()->assertSuccessful();
        $this->assertStringEndsWith('.jpg', $b->fresh()->logo);
    }

    public function test_traversal_and_absolute_paths_are_refused(): void
    {
        $b = $this->brand(['en' => 'Datalogic']);
        File::put(dirname($this->dir).'/edge-outside.png', base64_decode(self::PNG));
        foreach (['../edge-outside.png', '..\\edge-outside.png', 'img/../../edge-outside.png',
            str_replace('\\', '/', dirname($this->dir)).'/edge-outside.png', '.', '..', 'sub/x.png'] as $f) {
            $this->list(json_encode([['name' => 'Datalogic', 'logo' => $f]]));
            $this->run_()->assertFailed();
            $this->assertNull($b->fresh()->logo, $f);
        }
        File::delete(dirname($this->dir).'/edge-outside.png');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_null_byte_in_file_name_fails_cleanly(): void
    {
        $b = $this->brand(['en' => 'Godex']);
        $this->list('[{"name":"Godex","logo":"a.png\u0000.txt"}]');
        $this->run_()->assertFailed();
        $this->assertNull($b->fresh()->logo);
    }

    public function test_svg_html_oversized_and_truncated_files_are_refused(): void
    {
        $b = $this->brand(['en' => 'Urovo']);
        File::put($this->dir.'/img/a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        File::put($this->dir.'/img/b.png', '<html><body>x</body></html>');
        File::put($this->dir.'/img/c.png', base64_decode(self::PNG).str_repeat("\0", 2 * 1024 * 1024));
        File::put($this->dir.'/img/d.png', '');
        foreach (['a.svg', 'b.png', 'c.png', 'd.png'] as $f) {
            $this->list(json_encode([['name' => 'Urovo', 'logo' => $f]]));
            $this->run_()->assertFailed();
            $this->assertNull($b->fresh()->logo, $f);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_non_string_values_do_not_crash(): void
    {
        $this->brand(['en' => 'Zebra']);
        $this->list('[{"name":"Zebra","logo":["z.png"]}, {"name":{"en":"Zebra"},"logo":"z.png"}, 5, "x"]');
        $this->run_()->assertFailed();
    }

    public function test_inline_trailing_comment_is_reported_not_crash(): void
    {
        $this->brand(['en' => 'Zebra']);
        $this->list("[\n {\"name\":\"Zebra\",\"logo\":\"z.png\"}, // main brand\n]");
        $this->run_()->expectsOutputToContain('could not be read')->assertFailed();
    }

    public function test_empty_list_and_missing_list(): void
    {
        $this->list('export default [];');
        $this->run_()->assertSuccessful();

        File::delete($this->dir.'/brand.js');
        $this->run_()->expectsOutputToContain('Not found')->assertFailed();
    }

    public function test_dry_run_reports_up_to_date_and_keeps_old_logo(): void
    {
        Storage::disk('public')->put('brands/old.png', 'old');
        $b = $this->brand(['en' => 'Zebra']);
        $b->update(['logo' => 'brands/old.png']);
        File::put($this->dir.'/img/z.png', base64_decode(self::PNG));
        $this->list('[{"name":"Zebra","logo":"z.png"}]');

        $this->run_(['--dry-run' => true])->expectsOutputToContain('would set')->assertSuccessful();
        $this->assertSame('brands/old.png', $b->fresh()->logo);
        Storage::disk('public')->assertExists('brands/old.png');
    }

    public function test_logo_outside_brands_folder_is_not_deleted(): void
    {
        Storage::disk('public')->put('articles/keep.png', 'x');
        $b = $this->brand(['en' => 'Zebra']);
        $b->update(['logo' => 'articles/keep.png']);
        File::put($this->dir.'/img/z.png', base64_decode(self::PNG));
        $this->list('[{"name":"Zebra","logo":"z.png"}]');

        $this->run_()->assertSuccessful();
        Storage::disk('public')->assertExists('articles/keep.png');
    }

    public function test_duplicate_entries_leave_one_file(): void
    {
        $b = $this->brand(['en' => 'Zebra']);
        File::put($this->dir.'/img/a.png', base64_decode(self::PNG));
        File::put($this->dir.'/img/b.png', base64_decode(self::JPG));
        $this->list('[{"name":"Zebra","logo":"a.png"},{"name":"zebra","logo":"b.png"}]');

        $this->run_()->assertSuccessful();
        $this->assertSame([$b->fresh()->logo], Storage::disk('public')->allFiles());
    }

    public function test_dark_tiles_render_in_every_locale_without_raw_keys(): void
    {
        $this->brand(['uz' => 'Oʻzbek', 'ru' => 'Штрих', 'en' => 'Fallback Name']);
        $this->brand(['en' => 'Logo Brand'])->update(['logo' => 'brands/z.png']);

        foreach (['uz', 'ru', 'en'] as $l) {
            foreach (["/$l", "/$l/brands"] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_.]+\s*</', $html, $url);
                $this->assertMatchesRegularExpression('#<span class="[^"]*text-white[^"]*group-hover:text-gray-900#', $html, $url);
                $this->assertStringContainsString('brightness-0 invert', $html, $url);
            }
        }
    }
}
