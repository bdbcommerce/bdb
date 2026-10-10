<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\HeroBannerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_falls_back_to_built_in_copy_without_banners(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee(__('site.hero.title', [], 'en'))
            ->assertSee(__('site.hero.badge', [], 'en'))
            ->assertSee('bdb://scan/datamatrix')
            ->assertDontSee('aria-roledescription="carousel"', false);
    }

    public function test_seeded_hero_banner_renders_the_original_hero_in_every_locale(): void
    {
        $this->seed(HeroBannerSeeder::class);

        foreach (['uz', 'ru', 'en'] as $lc) {
            $html = $this->get("/{$lc}")->assertOk()->getContent();

            $this->assertStringContainsString(e(__('site.hero.title', [], $lc)), $html);
            $this->assertStringContainsString(e(__('site.hero.lead', [], $lc)), $html);
            foreach ((array) __('site.hero.points', [], $lc) as $point) {
                $this->assertStringContainsString(e($point), $html);
            }
            $this->assertStringContainsString('href="/'.$lc.'/contact"', $html);
            $this->assertStringContainsString('bdb://scan/datamatrix', $html);
        }
    }

    public function test_hero_banner_seeder_is_idempotent_and_ignores_other_banners(): void
    {
        Banner::create(['title' => ['en' => 'test', 'uz' => 'test', 'ru' => 'test']]);

        $this->seed(HeroBannerSeeder::class);
        $this->seed(HeroBannerSeeder::class);

        $this->assertSame(2, Banner::count());
    }

    public function test_several_banners_rotate_inside_the_hero(): void
    {
        $this->seed(HeroBannerSeeder::class);
        Banner::create([
            'title' => ['en' => 'Asl Belgi deadline is coming'],
            'subtitle' => ['en' => 'Get your marking line ready.'],
            'points' => ['en' => "Printers\nApplicators"],
            'button_text' => ['en' => 'Read more'],
            'button_url' => 'https://example.com/news',
            'show_ai_button' => false,
            'sort_order' => 1,
        ]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('aria-roledescription="carousel"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Asl Belgi deadline is coming', $html);
        $this->assertStringContainsString('href="https://example.com/news"', $html);
        $this->assertStringContainsString('Applicators', $html);
    }

    public function test_inactive_banners_are_not_shown(): void
    {
        Banner::create(['title' => ['en' => 'Hidden test slide'], 'is_active' => false]);

        $this->get('/en')->assertOk()->assertDontSee('Hidden test slide')->assertSee(__('site.hero.title', [], 'en'));
    }

    public function test_button_href_adds_locale_to_site_paths(): void
    {
        $banner = new Banner(['button_url' => '/contact']);
        $this->assertSame('/ru/contact', $banner->buttonHref('ru'));

        $banner->button_url = '/uz/brands';
        $this->assertSame('/uz/brands', $banner->buttonHref('en'));

        $banner->button_url = 'https://example.com';
        $this->assertSame('https://example.com', $banner->buttonHref('en'));
    }

    public function test_demo_content_seeder_fills_catalog_and_knowledge_once(): void
    {
        $this->seed(DemoContentSeeder::class);
        $this->seed(DemoContentSeeder::class);

        $this->assertSame(6, Brand::count());
        $this->assertSame(6, Industry::count());
        $this->assertSame(10, Customer::count());
        $this->assertSame(8, KnowledgeEntry::count());

        $this->get('/en')->assertOk()->assertSee('Zebra Technologies')->assertSee('Demo Supermarket Chain');
    }
}
