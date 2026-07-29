<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sitemap:generate')]
#[Description('Generate sitemap.xml in the public directory')]
class GenerateSitemap extends Command
{
    public function handle(): void
    {
        $locales = ['uz', 'ru', 'en'];
        $baseUrl = rtrim(config('app.url'), '/');

        $urls = [];

        // Static pages per locale
        $staticRoutes = ['/', '/about', '/brands', '/industries', '/customers', '/contact', '/news'];
        foreach ($locales as $locale) {
            foreach ($staticRoutes as $route) {
                $urls[] = $this->buildUrl($baseUrl, "/{$locale}{$route}");
            }
        }

        // Article category pages
        foreach (ArticleCategory::all() as $cat) {
            foreach ($locales as $locale) {
                $slug = $cat->getTranslation('slug', $locale, false);
                if ($slug) {
                    $urls[] = $this->buildUrl($baseUrl, "/{$locale}/news/{$slug}", $cat->updated_at);
                }
            }
        }

        // Individual articles
        foreach (Article::published()->get() as $article) {
            foreach ($locales as $locale) {
                $slug = $article->getTranslation('slug', $locale, false);
                if ($slug) {
                    $urls[] = $this->buildUrl($baseUrl, "/{$locale}/news/article/{$slug}", $article->published_at);
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
        foreach ($urls as $url) {
            $xml .= $url;
        }
        $xml .= '</urlset>';

        file_put_contents(public_path('sitemap.xml'), $xml);

        $this->info('sitemap.xml generated with ' . count($urls) . ' URLs.');
    }

    private function buildUrl(string $base, string $path, ?\Carbon\Carbon $lastmod = null): string
    {
        $loc = htmlspecialchars($base . $path);
        $entry = "  <url>\n    <loc>{$loc}</loc>\n";
        if ($lastmod) {
            $entry .= "    <lastmod>{$lastmod->toDateString()}</lastmod>\n";
        }
        $entry .= "  </url>\n";
        return $entry;
    }
}
