<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\ArticleImageSeeder;
use Database\Seeders\ArticleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: edge cases of the starter article illustrations. */
class ArticleImageSeederQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed([ArticleCategorySeeder::class, ArticleSeeder::class]);
    }

    public function test_every_bundled_slug_matches_a_starter_article_and_vice_versa(): void
    {
        $dir = database_path('seeders/data/');
        $articles = array_merge(require $dir.'articles_1.php', require $dir.'articles_2.php');
        $slugs = array_map(fn (array $a) => Str::slug($a[1]['en'], '-', 'en'), $articles);
        $files = array_map(fn (string $f) => basename($f, '.webp'), glob($dir.'article-images/*.webp'));

        sort($slugs);
        sort($files);
        $this->assertSame($slugs, $files);
        $this->assertCount(20, array_diff(scandir($dir.'article-images'), ['.', '..']), 'no stray files');
    }

    public function test_rerun_is_idempotent(): void
    {
        $before = Article::orderBy('id')->get(['id', 'featured_image', 'updated_at'])->toArray();
        $this->travel(5)->minutes();

        $this->seed(ArticleImageSeeder::class);
        $this->seed(ArticleImageSeeder::class);

        $this->assertSame($before, Article::orderBy('id')->get(['id', 'featured_image', 'updated_at'])->toArray());
        $this->assertCount(20, Storage::disk('public')->files('articles'));
    }

    public function test_article_whose_english_slug_was_changed_is_left_alone(): void
    {
        $article = Article::orderBy('id')->first();
        $oldPath = $article->featured_image;
        $article->setTranslation('slug', 'en', 'renamed-by-admin')->save();

        $this->seed(ArticleImageSeeder::class);

        $this->assertSame($oldPath, $article->fresh()->featured_image);
        Storage::disk('public')->assertExists($oldPath);
        Storage::disk('public')->assertMissing('articles/renamed-by-admin.webp');
    }

    public function test_renamed_article_without_image_gets_none(): void
    {
        $article = Article::orderBy('id')->first();
        $article->setTranslation('slug', 'en', 'renamed-by-admin');
        $article->featured_image = null;
        $article->save();

        $this->seed(ArticleImageSeeder::class);

        $this->assertNull($article->fresh()->featured_image);
    }

    public function test_admin_delete_removes_image_and_reseed_does_not_bring_it_back(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $article = Article::orderBy('id')->first();
        $path = $article->featured_image;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->delete(route('admin.articles.destroy', $article))
            ->assertRedirect(route('admin.articles.index'));

        Storage::disk('public')->assertMissing($path);
        $this->seed(ArticleImageSeeder::class);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(19, Article::whereNotNull('featured_image')->count());
    }

    public function test_article_seeder_still_skips_when_articles_exist_and_adds_no_images(): void
    {
        Article::query()->update(['featured_image' => null]);

        $this->seed(ArticleSeeder::class);

        $this->assertSame(20, Article::count());
        $this->assertSame(0, Article::whereNotNull('featured_image')->count(), 'existing DB needs --class=ArticleImageSeeder');
    }

    public function test_pages_show_the_illustration_in_every_locale(): void
    {
        $article = Article::orderBy('id')->first();
        $file = 'articles/'.$article->getTranslation('slug', 'en').'.webp';
        $absolute = url(Storage::url($file));

        foreach (['uz', 'ru', 'en'] as $locale) {
            $cat = $article->category->getTranslation('slug', $locale);

            foreach ([
                route('site.news', $locale),
                route('site.news.category', [$locale, $cat]),
            ] as $url) {
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertStringContainsString($file, $html, "{$locale} {$url}");
                $this->assertDoesNotMatchRegularExpression('/\bsite\.[a-z_]+\.[a-z_.]+\b/', strip_tags($html), "{$locale} raw key");
            }

            $html = $this->get(route('site.news.show', [$locale, $cat, $article->getTranslation('slug', $locale)]))
                ->assertOk()->getContent();
            $this->assertStringContainsString('<meta property="og:image" content="'.$absolute.'">', $html, $locale);
            $this->assertStringContainsString('"image":"'.$absolute.'"', $html, "{$locale} JSON-LD image");
            $this->assertStringContainsString('<img src="'.Storage::url($file).'"', $html, $locale);
        }
    }
}
