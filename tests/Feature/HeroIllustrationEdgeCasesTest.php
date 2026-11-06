<?php

namespace Tests\Feature;

use App\Livewire\Admin\BannerForm;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class HeroIllustrationEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function banner(array $attributes = []): Banner
    {
        return Banner::create(array_merge([
            'title' => ['uz' => 'Oʻzbekcha sarlavha', 'ru' => 'Заголовок', 'en' => 'Heading'],
            'is_active' => true,
        ], $attributes));
    }

    public function test_banner_admin_pages_require_an_admin(): void
    {
        $banner = $this->banner();

        $this->get(route('admin.banners.create'))->assertRedirect();
        $this->get(route('admin.banners.edit', $banner))->assertRedirect();

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.banners.create'))->assertRedirect(route('admin.login'));

        $this->actingAs($this->admin())->get(route('admin.banners.edit', $banner))->assertOk();
    }

    public function test_builtin_fallback_without_banners_shows_the_scanner_in_every_locale(): void
    {
        foreach (['uz', 'ru', 'en'] as $lc) {
            $html = $this->get("/{$lc}")->assertOk()->getContent();
            $this->assertStringContainsString('bdb://scan/datamatrix', $html, $lc);
            $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_.]+\s*</', $html, $lc);
        }
    }

    public function test_unknown_stored_key_falls_back_in_admin_form_and_index(): void
    {
        $banner = $this->banner();
        DB::table('banners')->where('id', $banner->id)->update(['illustration' => 'deleted-one']);

        $this->actingAs($this->admin());
        $this->get(route('admin.banners.index'))->assertOk()->assertSee('2D code scanning');
        $this->get(route('admin.banners.edit', $banner))->assertOk();

        Livewire::test(BannerForm::class, ['bannerId' => $banner->id])
            ->assertSet('illustration', 'scanner')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('scanner', $banner->fresh()->illustration);
    }

    public function test_remove_checkbox_is_only_shown_when_the_slide_has_an_image(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $plain = $this->banner();
        $this->get(route('admin.banners.edit', $plain))->assertOk()
            ->assertDontSee('Remove image and show the illustration instead');

        $withImage = $this->banner(['image' => 'banners/x.jpg']);
        $this->get(route('admin.banners.edit', $withImage))->assertOk()
            ->assertSee('Remove image and show the illustration instead')
            ->assertSee('This slide has an image, so the illustration is not shown');

        Livewire::test(BannerForm::class, ['bannerId' => $withImage->id])
            ->set('removeImage', true)
            ->assertDontSee('This slide has an image, so the illustration is not shown');
    }

    public function test_new_upload_wins_over_remove_and_old_file_is_deleted(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $old = UploadedFile::fake()->image('old.jpg')->store('banners', 'public');
        $banner = $this->banner(['image' => $old]);

        Livewire::test(BannerForm::class, ['bannerId' => $banner->id])
            ->set('removeImage', true)
            ->set('image', UploadedFile::fake()->image('new.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $new = $banner->fresh()->image;
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_failed_validation_keeps_the_image_and_the_chosen_state(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());
        $path = UploadedFile::fake()->image('hero.jpg')->store('banners', 'public');
        $banner = $this->banner(['image' => $path]);

        Livewire::test(BannerForm::class, ['bannerId' => $banner->id])
            ->set('removeImage', true)
            ->set('illustration', 'fiscal')
            ->set('titleEn', '')
            ->call('save')
            ->assertHasErrors('titleEn')
            ->assertSet('removeImage', true)
            ->assertSet('illustration', 'fiscal');

        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $banner->fresh()->image);
        $this->assertSame('scanner', $banner->fresh()->illustration);
    }

    public function test_remove_without_an_existing_image_is_harmless(): void
    {
        $this->actingAs($this->admin());
        $banner = $this->banner(['illustration' => 'rfid']);

        Livewire::test(BannerForm::class, ['bannerId' => $banner->id])
            ->set('removeImage', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($banner->fresh()->image);
        $this->assertSame('rfid', $banner->fresh()->illustration);
    }

    public function test_illustration_views_render_standalone_without_page_variables(): void
    {
        foreach (array_keys(Banner::ILLUSTRATIONS) as $key) {
            $html = view("site.partials.hero-illustrations.{$key}")->render();
            $this->assertStringContainsString('animate-blink', $html, $key);
        }
    }
}
