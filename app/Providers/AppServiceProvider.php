<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Models\SeoSetting;
use App\Services\Seo\IndexNow;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->register(ViewServiceProvider::class);

        // Public content changed: sitemap.xml and llms.txt are rebuilt on the next request.
        foreach ([Article::class, ArticleCategory::class, Brand::class,
            Industry::class, CompanyInfo::class, KnowledgeEntry::class] as $model) {
            $model::saved(fn () => SeoSetting::flush());
            $model::deleted(fn () => SeoSetting::flush());
        }

        // A published article was saved: tell Bing / Yandex right away (after the response).
        Article::saved(function (Article $article) {
            if (! $article->is_published || ! SeoSetting::current()->indexnow_enabled) {
                return;
            }
            $article->loadMissing('category');
            $urls = [];
            foreach (['uz', 'ru', 'en'] as $locale) {
                $slug = $article->getTranslation('slug', $locale, false);
                $category = $article->category?->getTranslation('slug', $locale, false);
                if ($slug && $category) {
                    $urls[] = route('site.news.show', [$locale, $category, $slug]);
                }
            }
            \Illuminate\Support\defer(fn () => app(IndexNow::class)->submit($urls));
        });
    }
}
