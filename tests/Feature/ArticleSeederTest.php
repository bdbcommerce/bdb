<?php

namespace Tests\Feature;

use App\Models\Article;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\ArticleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ArticleCategorySeeder::class, ArticleSeeder::class]);
    }

    public function test_seeds_twenty_published_articles_in_three_languages(): void
    {
        $this->assertSame(20, Article::published()->count());

        foreach (['uz', 'ru', 'en'] as $locale) {
            $slugs = Article::all()->map(fn (Article $a) => $a->getTranslation('slug', $locale, false));
            $this->assertSame(20, $slugs->filter()->unique()->count(), "unique {$locale} slugs");

            foreach (Article::all() as $article) {
                $this->assertNotEmpty($article->getTranslation('title', $locale, false));
                $this->assertNotEmpty($article->getTranslation('excerpt', $locale, false));
                $this->assertStringContainsString('<p>', $article->getTranslation('content', $locale, false));
            }
        }

        $perCategory = Article::with('category')->get()->toBase()
            ->countBy(fn ($a) => $a->category->getTranslation('slug', 'en'));
        $this->assertSame(['auto-id-news' => 8, 'tax-policy' => 5, 'asl-belgi' => 7], $perCategory->sortKeys()->only(['auto-id-news', 'tax-policy', 'asl-belgi'])->sortBy(fn ($n, $k) => array_search($k, ['auto-id-news', 'tax-policy', 'asl-belgi']))->all());
    }

    public function test_is_skipped_when_articles_exist(): void
    {
        $this->seed(ArticleSeeder::class);

        $this->assertSame(20, Article::count());
    }

    public function test_article_pages_open_in_every_language(): void
    {
        $article = Article::with('category')->first();

        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->get(route('site.news.show', [
                'locale' => $locale,
                'categorySlug' => $article->category->getTranslation('slug', $locale),
                'slug' => $article->getTranslation('slug', $locale),
            ]))->assertOk()->assertSee($article->getTranslation('title', $locale), false);
        }
    }

    public function test_articles_are_part_of_the_ai_knowledge(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => 'ok', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
            ])]]]]],
        ])]);

        $this->postJson('/chat/message', [
            'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Что такое агрегация?']],
        ])->assertOk();

        $article = Article::all()->first(fn ($a) => str_contains($a->getTranslation('title', 'ru'), 'Агрегация'));

        Http::assertSent(function (Request $r) use ($article) {
            $prompt = $r['system_instruction']['parts'][0]['text'];

            return str_contains($prompt, '## Articles')
                && str_contains($prompt, $article->getTranslation('title', 'ru'))
                && str_contains($prompt, '/ru/news/asl-belgi/'.$article->getTranslation('slug', 'ru'))
                && str_contains($prompt, 'групповой упаковке');
        });
    }
}
