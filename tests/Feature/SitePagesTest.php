<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\Customer;
use App\Models\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['uz', 'ru', 'en'];

    private const PAGES = ['', '/about', '/brands', '/industries', '/customers', '/contact', '/news'];

    private function t(string $value): array
    {
        return ['uz' => $value.' uz', 'ru' => $value.' ru', 'en' => $value.' en'];
    }

    public function test_pages_render_with_empty_content(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (self::PAGES as $page) {
                $this->get("/{$locale}{$page}")
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'">', false)
                    ->assertSee('og:title', false)
                    ->assertSee('belgiChat', false);
            }
        }
    }

    public function test_home_shows_landing_sections_and_empty_states(): void
    {
        $this->get('/en')
            ->assertOk()
            ->assertSee(__('site.hero.title', [], 'en'))
            ->assertSee('id="services"', false)
            ->assertSee(__('site.brands.empty_title', [], 'en'))
            ->assertSee(__('site.industries.empty_title', [], 'en'))
            ->assertSee('id="assistant"', false)
            ->assertDontSee(__('site.stats.heading', [], 'en'));
    }

    public function test_pages_render_with_content(): void
    {
        CompanyInfo::instance()->update([
            'slogan' => $this->t('Slogan'),
            'about' => $this->t('About text'),
            'address' => $this->t('Tashkent'),
            'phone' => '+998 71 000 00 00',
            'email' => 'info@example.uz',
            'telegram' => 'https://t.me/example',
        ]);

        $brand = Brand::create(['name' => $this->t('Zebra'), 'description' => $this->t('Printers'), 'is_active' => true, 'website' => 'https://example.com']);
        $industry = Industry::create(['name' => $this->t('Retail'), 'description' => $this->t('Shops'), 'icon' => 'shopping-cart', 'is_active' => true]);
        Industry::create(['name' => $this->t('Logistics'), 'icon' => 'not-a-real-icon', 'is_active' => true]);
        Customer::create(['name' => 'Korzinka', 'description' => $this->t('Retail chain'), 'industry_id' => $industry->id, 'is_active' => true]);

        $category = ArticleCategory::create(['name' => $this->t('Auto ID News'), 'slug' => ['uz' => 'auto-id-uz', 'ru' => 'auto-id-ru', 'en' => 'auto-id-en']]);
        $article = Article::create([
            'article_category_id' => $category->id,
            'title' => $this->t('Hello "world"'),
            'slug' => ['uz' => 'hello-uz', 'ru' => 'hello-ru', 'en' => 'hello-en'],
            'excerpt' => $this->t('Excerpt'),
            'content' => $this->t('<p>Body</p>'),
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);
        Article::create([
            'article_category_id' => $category->id,
            'title' => $this->t('Second'),
            'slug' => ['uz' => 'second-uz', 'ru' => 'second-ru', 'en' => 'second-en'],
            'content' => $this->t('<p>Second body</p>'),
            'is_published' => true,
            'published_at' => now()->subDays(2),
        ]);

        foreach (self::LOCALES as $locale) {
            foreach (self::PAGES as $page) {
                $this->get("/{$locale}{$page}")->assertOk();
            }
            $this->get("/{$locale}/news/auto-id-{$locale}")->assertOk()->assertSee('Hello');
            $this->get("/{$locale}/news/auto-id-{$locale}/hello-{$locale}")
                ->assertOk()
                ->assertSee('"@type":"NewsArticle"', false)
                ->assertSee('Second '.$locale);
        }

        $this->get('/en')
            ->assertOk()
            ->assertSee('Slogan en')
            ->assertSee('Zebra en')
            ->assertSee('Retail en')
            ->assertSee('Korzinka')
            ->assertSee(__('site.stats.clients', [], 'en'))
            ->assertSee('Hello &quot;world&quot; en', false);

        $this->get('/en/customers')->assertOk()->assertSee('Korzinka')->assertSee('Retail en');
        $this->get('/en/brands')->assertOk()->assertSee('Zebra en')->assertSee('https://example.com', false);
    }

    public function test_unknown_article_returns_404(): void
    {
        $this->get('/en/news/nothing/here')->assertNotFound();
    }
}
