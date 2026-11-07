<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Search & AI visibility settings (singleton). Admin → Company → SEO & AI visibility.
 */
class SeoSetting extends Model
{
    public const CACHE_KEY = 'seo.settings';

    /** Cache of the generated sitemap / llms.txt, flushed on any content or settings change. */
    public const FILES_CACHE = ['seo.sitemap', 'seo.llms'];

    /**
     * Crawlers listed in robots.txt: user agent => [company, what it does, allowed by default].
     * Search / user-request bots bring visitors and citations; training bots put the company
     * into the models' general knowledge.
     */
    public const CRAWLERS = [
        'Googlebot' => ['Google', 'Google Search, AI Overviews and AI Mode', true],
        'Google-Extended' => ['Google', 'Gemini: use of pages for answers and training', true],
        'bingbot' => ['Microsoft', 'Bing and Copilot (ChatGPT search also uses Bing)', true],
        'YandexBot' => ['Yandex', 'Yandex Search and Alice / YandexGPT', true],
        'OAI-SearchBot' => ['OpenAI', 'ChatGPT search results', true],
        'ChatGPT-User' => ['OpenAI', 'Pages a ChatGPT user asks to open', true],
        'GPTBot' => ['OpenAI', 'Training of OpenAI models', true],
        'Claude-SearchBot' => ['Anthropic', 'Claude search results', true],
        'Claude-User' => ['Anthropic', 'Pages a Claude user asks to open', true],
        'ClaudeBot' => ['Anthropic', 'Training of Claude models', true],
        'PerplexityBot' => ['Perplexity', 'Perplexity answers', true],
        'Perplexity-User' => ['Perplexity', 'Pages a Perplexity user asks to open', true],
        'Applebot' => ['Apple', 'Siri, Spotlight and Safari suggestions', true],
        'Applebot-Extended' => ['Apple', 'Apple Intelligence training', true],
        'DuckAssistBot' => ['DuckDuckGo', 'DuckDuckGo AI answers', true],
        'Amazonbot' => ['Amazon', 'Alexa and Amazon AI', true],
        'meta-externalagent' => ['Meta', 'Meta AI', true],
        'MistralAI-User' => ['Mistral', 'Pages a Le Chat user asks to open', true],
        'CCBot' => ['Common Crawl', 'Open web archive used to train many AI models', true],
        'Bytespider' => ['ByteDance', 'TikTok / Doubao AI — very heavy crawling, few visits back', false],
    ];

    /** Parts of the site no crawler needs. */
    public const PRIVATE_PATHS = ['/admin', '/chat/', '/telegram/'];

    /** Knowledge base types shown on the public FAQ by default (pricing and company notes stay internal). */
    public const DEFAULT_FAQ_TYPES = ['faq', 'asl_belgi', 'delivery', 'support'];

    protected $fillable = [
        'crawlers', 'robots_extra', 'llms_enabled', 'llms_summary', 'faq_enabled', 'faq_types', 'opening_hours',
        'google_verification', 'bing_verification', 'yandex_verification',
        'indexnow_enabled', 'indexnow_key', 'indexnow_last_at', 'indexnow_last_result',
    ];

    protected function casts(): array
    {
        return [
            'crawlers' => 'array', 'faq_types' => 'array', 'llms_enabled' => 'boolean', 'faq_enabled' => 'boolean',
            'indexnow_enabled' => 'boolean', 'indexnow_last_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flush());
    }

    /** The settings row (created with defaults on first use). Never throws: a missing table means defaults. */
    public static function current(): self
    {
        try {
            // The raw attributes are cached (not the model object), like ColorTheme::active().
            $attributes = Cache::rememberForever(self::CACHE_KEY, fn () => static::firstOrCreate(['id' => 1], [
                'faq_types' => self::DEFAULT_FAQ_TYPES,
                'indexnow_key' => Str::lower(Str::random(32)),
            ])->refresh()->getAttributes()); // refresh: pick up column defaults (llms_enabled…)

            return (new static)->newFromBuilder($attributes);
        } catch (Throwable) {
            return new static(['llms_enabled' => true, 'faq_types' => self::DEFAULT_FAQ_TYPES]);
        }
    }

    /** Forget the settings and the generated files (sitemap, llms.txt). */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        foreach (self::FILES_CACHE as $key) {
            Cache::forget($key);
        }
        foreach (['uz', 'ru', 'en'] as $locale) {
            Cache::forget('seo.brand-names.'.$locale);
        }
    }

    public function allows(string $bot): bool
    {
        return (bool) ($this->crawlers[$bot] ?? self::CRAWLERS[$bot][2] ?? true);
    }

    public function faqTypes(): array
    {
        return array_values(array_intersect($this->faq_types ?? self::DEFAULT_FAQ_TYPES, array_keys(KnowledgeEntry::TYPES)));
    }

    /** IndexNow key (letters/digits, as the protocol requires), created on first use. */
    public function indexNowKey(): string
    {
        if (! preg_match('/^[a-z0-9]{8,64}$/', (string) $this->indexnow_key)) {
            $this->indexnow_key = Str::lower(Str::random(32));
            if ($this->exists) {
                $this->save();
            }
        }

        return $this->indexnow_key;
    }
}
