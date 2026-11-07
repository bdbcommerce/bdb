<?php

namespace App\Services\Seo;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\Industry;
use App\Models\SeoSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The files crawlers read: robots.txt, sitemap.xml and llms.txt, built from the live
 * content and the admin's SEO settings, always with this site's real address.
 */
class SeoFiles
{
    public const LOCALES = ['uz', 'ru', 'en'];

    private const STATIC_PAGES = ['site.home', 'site.about', 'site.brands', 'site.industries', 'site.customers', 'site.contact', 'site.news'];

    public function robots(): string
    {
        $settings = SeoSetting::current();
        $private = array_map(fn ($p) => 'Disallow: '.$p, SeoSetting::PRIVATE_PATHS);

        // Admin's extra rules: plain Allow/Disallow lines (before any "User-agent") apply to every
        // crawler that is let in, so they are added to each group; a "User-agent" block and the
        // lines after it form the admin's own groups at the end.
        $common = [];
        $ownGroups = [];
        $globals = [];
        foreach (preg_split('/\R/', trim((string) $settings->robots_extra)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! $ownGroups && preg_match('/^(allow|disallow|crawl-delay|clean-param)\s*:/i', $line)) { // before any own User-agent block
                $common[] = $line;
            } elseif ($ownGroups || preg_match('/^user-agent\s*:/i', $line)) {
                $ownGroups[] = $line;
            } elseif (preg_match('/^sitemap\s*:/i', $line)) {
                $globals[] = $line; // not part of any group
            }
            // comments before any group are dropped
        }
        $rules = [...$private, ...$common];

        $lines = ['# Search engines and AI assistants are welcome; the admin area is private.', 'User-agent: *', 'Allow: /', ...$rules, ''];

        // A named group replaces the "*" group for that bot, so it repeats the shared rules.
        foreach (SeoSetting::CRAWLERS as $bot => [$company, $purpose]) {
            $lines[] = "# {$company}: {$purpose}";
            $lines[] = 'User-agent: '.$bot;
            $lines = [...$lines, ...($settings->allows($bot) ? ['Allow: /', ...$rules] : ['Disallow: /']), ''];
        }

        if ($ownGroups || $globals) {
            $lines = [...$lines, '# Added by the site admin', ...$ownGroups, ...$globals, ''];
        }

        $lines[] = 'Sitemap: '.self::publicUrl(url('/sitemap.xml'));

        return implode("\n", $lines)."\n";
    }

    /** All public pages in every language, each with its language alternates and last change. */
    public function sitemap(): string
    {
        return Cache::remember('seo.sitemap', now()->addHour(), function () {
            $entries = [];
            $add = function (array $urls, $lastmod = null) use (&$entries) {
                $entries[] = [$urls, $lastmod];
            };

            $pages = self::STATIC_PAGES;
            if (SeoSetting::current()->faq_enabled) {
                $pages[] = 'site.faq';
            }
            $contentChanged = collect([Brand::max('updated_at'), Industry::max('updated_at'), CompanyInfo::max('updated_at')])->filter()->max();
            foreach ($pages as $name) {
                $add($this->perLocale(fn ($l) => self::publicUrl(route($name, $l))), $contentChanged);
            }

            foreach (ArticleCategory::all() as $category) {
                $add($this->perLocale(fn ($l) => ($slug = $category->getTranslation('slug', $l, false))
                    ? self::publicUrl(route('site.news.category', [$l, $slug])) : null), $category->updated_at);
            }

            foreach (Article::published()->with('category')->get() as $article) {
                $add($this->perLocale(function ($l) use ($article) {
                    $slug = $article->getTranslation('slug', $l, false);
                    $category = $article->category?->getTranslation('slug', $l, false);

                    return $slug && $category ? self::publicUrl(route('site.news.show', [$l, $category, $slug])) : null;
                }), $article->updated_at ?? $article->published_at);
            }

            $xml = ['<?xml version="1.0" encoding="UTF-8"?>',
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'];
            foreach ($entries as [$urls, $lastmod]) {
                foreach ($urls as $url) {
                    $xml[] = '  <url>';
                    $xml[] = '    <loc>'.e($url).'</loc>';
                    foreach ($urls as $locale => $alternate) {
                        $xml[] = '    <xhtml:link rel="alternate" hreflang="'.$locale.'" href="'.e($alternate).'"/>';
                    }
                    if ($lastmod) {
                        $xml[] = '    <lastmod>'.Carbon::parse($lastmod)->toDateString().'</lastmod>';
                    }
                    $xml[] = '  </url>';
                }
            }
            $xml[] = '</urlset>';

            return implode("\n", $xml)."\n";
        });
    }

    /** Every URL in the sitemap (for IndexNow). */
    public function urls(): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $this->sitemap(), $m);

        return array_map(fn ($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1]);
    }

    /** llms.txt: a plain summary of the company for AI assistants (llmstxt.org format). */
    public function llms(): string
    {
        return Cache::remember('seo.llms', now()->addHour(), function () {
            $info = CompanyInfo::instance();
            $settings = SeoSetting::current();
            $t = fn ($model, string $field) => $model->getTranslation($field, 'en', false)
                ?: $model->getTranslation($field, 'ru', false) ?: $model->getTranslation($field, 'uz', false);
            $line = fn (?string $text, int $max = 220) => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text))), $max);

            $summary = trim((string) $settings->llms_summary) ?: ($t($info, 'about') ?: $t($info, 'slogan')
                ?: 'BDB Auto ID is an automatic identification (AIDC) company in Uzbekistan: barcode and RFID equipment, labels, and Asl Belgi product marking.');

            $out = ['# BDB Auto ID', '', '> '.$line($summary, 600), ''];

            $facts = array_filter([
                'Website' => self::publicUrl(url('/')),
                'Languages' => 'Uzbek (/uz), Russian (/ru), English (/en)',
                'Address' => $t($info, 'address'),
                'Phone' => $info->phone,
                'Email' => $info->email,
                'Opening hours' => $settings->opening_hours,
                'Online shop' => $info->shopUrl('llms'),
                'Map' => $info->yandexMapLink(),
                'Telegram' => $info->telegram,
            ]);
            foreach ($facts as $label => $value) {
                $out[] = "- {$label}: {$value}";
            }

            $out = [...$out, '', '## Main pages', ''];
            foreach ([
                'site.about' => ['About the company', 'who BDB is and what it does'],
                'site.brands' => ['Brands', 'equipment brands BDB supplies and services'],
                'site.industries' => ['Industries', 'solutions by industry'],
                'site.customers' => ['Customers', 'companies that work with BDB'],
                'site.news' => ['News and articles', 'Auto ID, Asl Belgi marking, tax policy'],
                'site.contact' => ['Contact', 'address, phone, map'],
            ] + ($settings->faq_enabled ? ['site.faq' => ['FAQ', 'answers to common customer questions']] : []) as $route => [$title, $about]) {
                $out[] = "- [{$title}](".self::publicUrl(route($route, 'en'))."): {$about} (Russian: ".self::publicUrl(route($route, 'ru')).', Uzbek: '.self::publicUrl(route($route, 'uz')).')';
            }

            $brands = Brand::query()->where('is_active', true)->orderBy('sort_order')->get();
            if ($brands->isNotEmpty()) {
                $out = [...$out, '', '## Brands', ''];
                foreach ($brands as $brand) {
                    $out[] = '- '.$t($brand, 'name').(($d = $line($t($brand, 'description'))) ? ': '.$d : '');
                }
            }

            $industries = Industry::query()->where('is_active', true)->get();
            if ($industries->isNotEmpty()) {
                $out = [...$out, '', '## Industries', ''];
                foreach ($industries as $industry) {
                    $out[] = '- '.$t($industry, 'name').(($d = $line($t($industry, 'description'))) ? ': '.$d : '');
                }
            }

            $articles = Article::published()->with('category')->take(30)->get();
            if ($articles->isNotEmpty()) {
                $out = [...$out, '', '## Articles', ''];
                foreach ($articles as $article) {
                    foreach (['en', 'ru', 'uz'] as $l) {
                        $slug = $article->getTranslation('slug', $l, false);
                        $category = $article->category?->getTranslation('slug', $l, false);
                        if ($slug && $category && ($title = $article->getTranslation('title', $l, false))) {
                            $out[] = "- [{$line($title, 160)}](".self::publicUrl(route('site.news.show', [$l, $category, $slug])).')'
                                .(($e = $line($article->getTranslation('excerpt', $l, false), 200)) ? ': '.$e : '');
                            break;
                        }
                    }
                }
            }

            return implode("\n", $out)."\n";
        });
    }

    /**
     * A link on the public address (APP_URL), whatever host the current request came in on
     * (e.g. www, an IP, or the admin's own address).
     */
    public static function publicUrl(string $url): string
    {
        $root = rtrim(url('/'), '/');
        $base = rtrim((string) config('app.url'), '/');

        return $base !== '' && $root !== $base && str_starts_with($url, $root) ? $base.substr($url, strlen($root)) : $url;
    }

    /** @return array<string, string> locale => url, for the locales that have the page */
    private function perLocale(callable $url): array
    {
        $urls = [];
        foreach (self::LOCALES as $locale) {
            if ($u = $url($locale)) {
                $urls[$locale] = $u;
            }
        }

        return $urls;
    }
}
