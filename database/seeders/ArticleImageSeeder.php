<?php

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Abstract illustrations (16:9 WebP, database/seeders/data/article-images/<english slug>.webp) for the
 * starter articles. Called by ArticleSeeder; on an existing database run
 * `php artisan db:seed --class=ArticleImageSeeder --force`.
 *
 * Safe to re-run: an article is matched by its English slug; an image an admin uploaded is kept,
 * a missing one or one this seeder placed (articles/<slug>.webp) is (re)placed.
 */
class ArticleImageSeeder extends Seeder
{
    public function run(): void
    {
        $disk = Storage::disk('public');
        $set = 0;

        foreach (glob(database_path('seeders/data/article-images/*.webp')) ?: [] as $file) {
            $slug = basename($file, '.webp');
            $path = "articles/{$slug}.webp";

            // Only the oldest article with this slug: a duplicate must not share (and on delete remove) the file.
            foreach (Article::where('slug->en', $slug)->orderBy('id')->limit(1)->get() as $article) {
                $current = $article->featured_image;
                if ($current && $current !== $path && $disk->exists($current)) {
                    continue; // the admin's own image
                }

                $disk->put($path, file_get_contents($file));
                if ($current !== $path) {
                    $article->update(['featured_image' => $path]);
                }
                $set++;
            }
        }

        $this->command?->info("Article illustrations set: {$set}.");
    }
}
