<?php

namespace Tests\Feature;

use App\Models\Banner;
use Database\Seeders\HeroBannerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeroBannerSeederContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_slide_has_complete_copy_in_all_locales(): void
    {
        $this->seed(HeroBannerSeeder::class);

        $this->assertSame(4, Banner::count());

        foreach (Banner::orderBy('sort_order')->get() as $banner) {
            $this->assertTrue($banner->is_active);
            $this->assertNull($banner->image);
            $this->assertSame('/contact', $banner->button_url);

            foreach (['uz', 'ru', 'en'] as $lc) {
                foreach (['badge', 'title', 'subtitle', 'points', 'button_text'] as $field) {
                    $value = (string) $banner->getTranslation($field, $lc, false);
                    $this->assertNotSame('', trim($value), "{$field}.{$lc} empty on slide {$banner->sort_order}");
                    $this->assertStringNotContainsString('site.', $value, "{$field}.{$lc} is a raw key");
                }
                $this->assertCount(3, $banner->pointList($lc));
                $this->assertLessThanOrEqual(80, mb_strlen($banner->getTranslation('title', $lc)));
            }

            // Uzbek Latin copy uses the modifier letters, not ASCII/curly apostrophes.
            foreach (['badge', 'title', 'subtitle', 'points'] as $field) {
                $uz = (string) $banner->getTranslation($field, 'uz', false);
                $this->assertDoesNotMatchRegularExpression("/\p{L}['`\x{2018}\x{2019}]\p{L}/u", $uz);
                $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $uz);
            }
        }
    }

    public function test_all_slide_texts_and_illustrations_render_on_every_locale(): void
    {
        $this->seed(HeroBannerSeeder::class);

        foreach (['uz', 'ru', 'en'] as $lc) {
            $html = $this->get("/{$lc}")->assertOk()->getContent();

            foreach (Banner::active()->get() as $banner) {
                $this->assertStringContainsString(e($banner->getTranslation('badge', $lc)), $html);
                $this->assertStringContainsString(e($banner->getTranslation('subtitle', $lc)), $html);
                foreach ($banner->pointList($lc) as $point) {
                    $this->assertStringContainsString(e($point), $html);
                }
            }

            foreach (['bdb://scan/datamatrix', 'bdb://rfid/uhf-gen', 'bdb://fiscal/receipt', 'bdb://print/gs'] as $marker) {
                $this->assertStringContainsString($marker, $html);
            }
            $this->assertStringContainsString('href="/'.$lc.'/contact"', $html);
            $this->assertDoesNotMatchRegularExpression('/>\s*site\.[a-z_.]+\s*</', $html);
        }
    }

    public function test_upgrading_an_install_that_only_has_the_old_default_hero_adds_just_the_new_slides(): void
    {
        // Pre-change installs already have the default hero (same English title).
        Banner::create([
            'title' => ['en' => __('site.hero.title', [], 'en'), 'uz' => 'edited', 'ru' => 'edited'],
            'sort_order' => 0,
        ]);

        $this->seed(HeroBannerSeeder::class);

        $this->assertSame(4, Banner::count());
        $this->assertSame(1, Banner::where('title->en', __('site.hero.title', [], 'en'))->count());
        $this->assertSame('edited', Banner::orderBy('sort_order')->first()->getTranslation('title', 'uz'));
        $this->assertEqualsCanonicalizing(
            ['rfid', 'fiscal', 'printer'],
            Banner::where('sort_order', '>', 0)->pluck('illustration')->all()
        );
    }
}
