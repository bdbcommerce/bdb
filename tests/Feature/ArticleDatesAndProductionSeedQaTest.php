<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\ColorTheme;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Models\User;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleDatesAndProductionSeedQaTest extends TestCase
{
    use RefreshDatabase;

    private function makeArticle(): Article
    {
        $this->seed(ArticleCategorySeeder::class);
        $category = ArticleCategory::orderBy('sort_order')->first();

        return Article::create([
            'article_category_id' => $category->id,
            'title' => ['uz' => 'Sinov maqola', 'ru' => 'Тестовая статья', 'en' => 'Test article'],
            'slug' => ['uz' => 'sinov-maqola', 'ru' => 'testovaya-statya', 'en' => 'test-article'],
            'excerpt' => ['uz' => 'Qisqa', 'ru' => 'Кратко', 'en' => 'Short'],
            'content' => ['uz' => '<p>Matn</p>', 'ru' => '<p>Текст</p>', 'en' => '<p>Text</p>'],
            'is_published' => true,
            'published_at' => '2025-11-05 10:00:00',
        ]);
    }

    private function pages(Article $article, string $locale): array
    {
        $catSlug = $article->category->getTranslation('slug', $locale);

        return [
            'list' => route('site.news', $locale),
            'category' => route('site.news.category', [$locale, $catSlug]),
            'show' => route('site.news.show', [$locale, $catSlug, $article->getTranslation('slug', $locale)]),
            'home' => route('site.home', $locale),
        ];
    }

    public function test_uzbek_pages_print_latin_month_names(): void
    {
        $article = $this->makeArticle();

        foreach ($this->pages($article, 'uz') as $name => $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('ноя', $html, "uz {$name}: Cyrillic month");
            $this->assertStringNotContainsString('ноябр', $html, "uz {$name}: Cyrillic month");
            $expected = $name === 'show' ? '5 Noyabr 2025' : '5 Noy 2025';
            $this->assertStringContainsString($expected, $html, "uz {$name}");
        }
    }

    public function test_russian_and_english_dates_are_unchanged(): void
    {
        $article = $this->makeArticle();

        $expected = [
            'ru' => ['card' => '5 ноя 2025', 'show' => '5 ноября 2025'],
            'en' => ['card' => '5 Nov 2025', 'show' => '5 November 2025'],
        ];

        foreach ($expected as $locale => $fmt) {
            foreach ($this->pages($article, $locale) as $name => $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertStringContainsString($name === 'show' ? $fmt['show'] : $fmt['card'], $html, "{$locale} {$name}");
                $this->assertStringNotContainsString('Noy', $html, "{$locale} {$name}");
            }
        }
    }

    public function test_uzbek_home_description_has_no_cyrillic_letters(): void
    {
        $desc = __('seo.home_desc', [], 'uz');

        $this->assertStringContainsString('skanerlar', $desc);
        $this->assertDoesNotMatchRegularExpression('/\p{Cyrillic}/u', $desc);
    }

    private function seedAs(string $env): void
    {
        Http::fake();
        config(['app.admin' => ['name' => 'QA Admin', 'email' => 'qa-admin@example.test', 'password' => 'secret-pass-123']]);
        $this->app['env'] = $env;
        $this->assertSame($env === 'production', app()->isProduction());

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    }

    private function assertBaseSeeded(): void
    {
        $this->assertTrue(User::where('email', 'qa-admin@example.test')->where('is_admin', true)->exists(), 'admin user');
        $this->assertSame(3, ArticleCategory::count(), 'categories');
        $this->assertGreaterThan(0, Banner::count(), 'banners');
        $this->assertGreaterThan(0, AiModel::count(), 'AI models');
        $this->assertGreaterThan(0, ColorTheme::count(), 'colour themes');
    }

    public function test_production_seed_skips_demo_content(): void
    {
        $this->seedAs('production');

        $this->assertBaseSeeded();
        $this->assertSame(0, Brand::count(), 'brands');
        $this->assertSame(0, Industry::count(), 'industries');
        $this->assertSame(0, Customer::count(), 'customers');
        $this->assertSame(0, Article::count(), 'articles');
        $this->assertSame(0, KnowledgeEntry::count(), 'knowledge entries');
    }

    public function test_local_seed_includes_demo_content(): void
    {
        $this->seedAs('local');

        $this->assertBaseSeeded();
        $this->assertGreaterThan(0, Brand::count(), 'brands');
        $this->assertGreaterThan(0, Industry::count(), 'industries');
        $this->assertGreaterThan(0, Customer::count(), 'customers');
        $this->assertSame(20, Article::count(), 'articles');
        $this->assertGreaterThan(0, KnowledgeEntry::count(), 'knowledge entries');
    }

    public function test_production_seed_is_idempotent(): void
    {
        $this->seedAs('production');
        $counts = [User::count(), ArticleCategory::count(), Banner::count(), AiModel::count(), ColorTheme::count()];

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame($counts, [User::count(), ArticleCategory::count(), Banner::count(), AiModel::count(), ColorTheme::count()]);
        $this->assertSame(0, Brand::count());
    }
}
