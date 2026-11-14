<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Models\SeoSetting;
use App\Models\Vacancy;
use App\Services\Seo\IndexNow;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Own counter per public endpoint (a plain throttle:N,M shares one key per IP across routes).
        RateLimiter::for('chat-message', fn (Request $r) => Limit::perMinute(20)->by('chat-message|'.$r->ip()));
        RateLimiter::for('chat-lead', fn (Request $r) => Limit::perMinute(5)->by('chat-lead|'.$r->ip()));
        RateLimiter::for('careers', fn (Request $r) => [
            Limit::perMinutes(10, 5)->by('careers|'.$r->ip()),
            Limit::perDay(200)->by('careers|all'), // caps CV storage growth even from many IPs
        ]);
        // Brand link redirects (/go/…): the redirect always works; this only slows down scripted floods.
        RateLimiter::for('brand-links', fn (Request $r) => Limit::perMinute(20)->by('brand-links|'.$r->ip()));
        $this->app->register(ViewServiceProvider::class);

        // Public content changed: sitemap.xml and llms.txt are rebuilt on the next request.
        foreach ([Article::class, ArticleCategory::class, Brand::class,
            Industry::class, CompanyInfo::class, KnowledgeEntry::class, Vacancy::class] as $model) {
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
