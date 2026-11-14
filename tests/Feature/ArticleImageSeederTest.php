<?php

namespace Tests\Feature;

use App\Models\Article;
use Database\Seeders\ArticleImageSeeder;
use Database\Seeders\ArticleSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Abstract illustrations for the 20 starter articles. */
class ArticleImageSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class); // categories (+ demo content outside production)
        if (Article::count() === 0) {
            $this->seed(ArticleSeeder::class);
        }
    }

    public function test_every_starter_article_gets_its_illustration(): void
    {
        $this->assertSame(20, Article::count());
        foreach (Article::all() as $article) {
            $slug = $article->getTranslation('slug', 'en');
            $this->assertSame("articles/{$slug}.webp", $article->featured_image, $slug);
            Storage::disk('public')->assertExists($article->featured_image);
        }
    }

    public function test_bundled_images_are_16_9_webp_for_every_article(): void
    {
        $files = glob(database_path('seeders/data/article-images/*.webp'));
        $this->assertCount(20, $files);
        foreach ($files as $file) {
            $info = getimagesize($file);
            $this->assertSame([1280, 720, IMAGETYPE_WEBP], [$info[0], $info[1], $info[2]], basename($file));
            $this->assertLessThan(80 * 1024, filesize($file), basename($file));
        }
    }

    public function test_admin_image_is_kept_missing_or_own_image_is_replaced(): void
    {
        [$a, $b, $c] = Article::orderBy('id')->take(3)->get()->all();
        Storage::disk('public')->put('articles/admin.jpg', 'x');
        $a->update(['featured_image' => 'articles/admin.jpg']);
        $b->update(['featured_image' => 'articles/gone.jpg']);
        $c->update(['featured_image' => null]);
        Storage::disk('public')->put($own = 'articles/'.Article::find(4)->getTranslation('slug', 'en').'.webp', 'old');

        $this->seed(ArticleImageSeeder::class);

        $this->assertSame('articles/admin.jpg', $a->fresh()->featured_image);
        $this->assertSame('articles/'.$b->getTranslation('slug', 'en').'.webp', $b->fresh()->featured_image);
        $this->assertSame('articles/'.$c->getTranslation('slug', 'en').'.webp', $c->fresh()->featured_image);
        $this->assertNotSame('old', Storage::disk('public')->get($own), 'own image refreshed');
    }

    public function test_a_duplicate_with_the_same_slug_does_not_share_the_image_file(): void
    {
        $original = Article::orderBy('id')->first();
        $copy = $original->replicate()->fill(['featured_image' => null]);
        $copy->save();

        $this->seed(ArticleImageSeeder::class);

        $this->assertNull($copy->fresh()->featured_image);
        $this->assertSame('articles/'.$original->getTranslation('slug', 'en').'.webp', $original->fresh()->featured_image);
    }

    public function test_news_list_and_article_page_show_the_illustration(): void
    {
        $article = Article::orderBy('id')->first();
        $slug = $article->getTranslation('slug', 'en');

        $this->get('/en/news')->assertOk()->assertSee("articles/{$slug}.webp", false);
        $this->get(route('site.news.show', ['locale' => 'en', 'categorySlug' => $article->category->getTranslation('slug', 'en'), 'slug' => $slug]))
            ->assertOk()->assertSee("articles/{$slug}.webp", false);
    }
}
