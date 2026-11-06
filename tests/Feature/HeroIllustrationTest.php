<?php

namespace Tests\Feature;

use App\Livewire\Admin\BannerForm;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class HeroIllustrationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKERS = [
        'scanner' => 'bdb://scan/datamatrix',
        'rfid' => 'bdb://rfid/uhf-gen2',
        'printer' => 'bdb://print/gs1-128',
        'terminal' => 'bdb://mobile/stock-count',
        'aggregation' => 'bdb://trace/aggregation',
        'fiscal' => 'bdb://fiscal/receipt',
    ];

    private function banner(array $attributes = []): Banner
    {
        return Banner::create(array_merge([
            'title' => ['uz' => 'Sarlavha', 'ru' => 'Заголовок', 'en' => 'Heading'],
            'is_active' => true,
        ], $attributes));
    }

    public function test_every_illustration_has_a_view_and_a_marker(): void
    {
        $this->assertSame(array_keys(self::MARKERS), array_keys(Banner::ILLUSTRATIONS));
        foreach (array_keys(Banner::ILLUSTRATIONS) as $key) {
            $this->assertTrue(view()->exists("site.partials.hero-illustrations.{$key}"), $key);
        }
    }

    public function test_each_illustration_renders_on_the_home_page_in_every_locale(): void
    {
        $banner = $this->banner();

        foreach (self::MARKERS as $key => $marker) {
            $banner->update(['illustration' => $key]);
            foreach (['uz', 'ru', 'en'] as $lc) {
                $html = $this->get("/{$lc}")->assertOk()->getContent();
                $this->assertStringContainsString($marker, $html, "{$key} / {$lc}");
                foreach (array_diff(self::MARKERS, [$marker]) as $other) {
                    $this->assertStringNotContainsString($other, $html, "{$key} / {$lc}");
                }
            }
        }
    }

    public function test_each_slide_uses_its_own_illustration(): void
    {
        $this->banner(['illustration' => 'rfid', 'sort_order' => 0]);
        $this->banner(['illustration' => 'fiscal', 'sort_order' => 1]);

        $this->get('/en')->assertOk()
            ->assertSee(self::MARKERS['rfid'])
            ->assertSee(self::MARKERS['fiscal'])
            ->assertDontSee(self::MARKERS['scanner']);
    }

    public function test_new_banners_default_to_the_scanner_and_unknown_keys_fall_back(): void
    {
        $banner = $this->banner();
        $this->assertSame('scanner', $banner->fresh()->illustration);

        $banner->update(['illustration' => 'missing']);
        $this->get('/en')->assertOk()->assertSee(self::MARKERS['scanner']);
    }

    public function test_an_uploaded_image_replaces_the_illustration(): void
    {
        $this->banner(['illustration' => 'rfid', 'image' => 'banners/photo.jpg']);

        $this->get('/en')->assertOk()
            ->assertSee('/storage/banners/photo.jpg')
            ->assertDontSee(self::MARKERS['rfid']);
    }

    public function test_admin_can_choose_an_illustration(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $banner = $this->banner();

        Livewire::test(BannerForm::class, ['bannerId' => $banner->id])
            ->assertSet('illustration', 'scanner')
            ->set('illustration', 'printer')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('printer', $banner->fresh()->illustration);

        Livewire::test(BannerForm::class)
            ->set('titleUz', 'a')->set('titleRu', 'b')->set('titleEn', 'c')
            ->set('illustration', 'aggregation')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('aggregation', Banner::where('title->en', 'c')->value('illustration'));
    }

    public function test_unknown_illustration_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        Livewire::test(BannerForm::class)
            ->set('titleUz', 'a')->set('titleRu', 'b')->set('titleEn', 'c')
            ->set('illustration', '../../layout')
            ->call('save')
            ->assertHasErrors('illustration');

        $this->assertSame(0, Banner::count());
    }

    public function test_admin_can_remove_the_image_to_show_the_illustration(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $path = UploadedFile::fake()->image('hero.jpg')->store('banners', 'public');
        $banner = $this->banner(['image' => $path, 'illustration' => 'terminal']);

        Livewire::test(BannerForm::class, ['bannerId' => $banner->id])
            ->set('removeImage', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($banner->fresh()->image);
        Storage::disk('public')->assertMissing($path);
        $this->get('/en')->assertSee(self::MARKERS['terminal']);
    }

    public function test_banner_form_shows_previews_of_all_illustrations(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.banners.create'))->assertOk()->getContent();
        foreach (Banner::ILLUSTRATIONS as $key => [$label]) {
            $this->assertStringContainsString(self::MARKERS[$key], $html);
            $this->assertStringContainsString(e($label), $html);
        }

        $this->banner(['illustration' => 'fiscal']);
        $this->actingAs($admin)->get(route('admin.banners.index'))->assertOk()->assertSee('Fiscal receipt');
    }
}
