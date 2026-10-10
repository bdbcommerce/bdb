<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 20 starter articles (uz/ru/en) about auto ID, digital tax rules and Asl Belgi marking
 * in Uzbekistan, from database/seeders/data/articles_*.php. The AI assistant uses
 * published articles as knowledge, so review them before publishing on production:
 *
 * php artisan db:seed --class=ArticleSeeder --force
 *
 * Needs the article categories (ArticleCategorySeeder). Skipped when articles already exist.
 */
class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        if (Article::count() > 0) {
            $this->command?->info('Articles already exist, skipping starter articles.');

            return;
        }

        $categories = ArticleCategory::all()->keyBy(fn (ArticleCategory $c) => $c->getTranslation('slug', 'en'));
        $articles = array_merge(require __DIR__.'/data/articles_1.php', require __DIR__.'/data/articles_2.php');

        foreach ($articles as $i => [$category, $title, $excerpt, $content]) {
            if (! $categories->has($category)) {
                $this->command?->warn("Category {$category} not found, skipping \"{$title['en']}\".");

                continue;
            }

            Article::create([
                'article_category_id' => $categories[$category]->id,
                'title' => $title,
                'slug' => collect($title)->map(fn (string $t, string $locale) => Str::slug($t, '-', $locale))->all(),
                'excerpt' => $excerpt,
                'content' => $content,
                'is_published' => true,
                // Spread over the past months, newest first in the list.
                'published_at' => now()->subDays($i * 6 + 1)->setTime(10, 0),
                'sort_order' => $i,
            ]);
        }
    }
}
