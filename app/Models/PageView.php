<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * One public page view (Admin → Site analytics). Built from the request only: no IP address or
 * cookie is stored; the visitor hash rotates daily (app key + date + IP + browser).
 */
class PageView extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 400;

    public $timestamps = false;

    protected $fillable = ['visited_at', 'path', 'route', 'locale', 'referrer_host', 'source', 'utm_source', 'device', 'visitor_hash', 'is_bot', 'bot'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime', 'is_bot' => 'boolean'];
    }

    public const SOURCES = ['direct' => 'Direct / typed', 'search' => 'Search engines', 'ai' => 'AI assistants', 'social' => 'Social & messengers', 'other' => 'Other sites'];

    /** Referrer host patterns → [source, display name]. AI assistants first. */
    public const REFERRERS = [
        '/(^|\.)(chatgpt\.com|chat\.openai\.com)$/' => ['ai', 'ChatGPT'],
        '/(^|\.)perplexity\.ai$/' => ['ai', 'Perplexity'],
        '/(^|\.)claude\.ai$/' => ['ai', 'Claude'],
        '/(^|\.)(copilot\.microsoft\.com|copilot\.cloud\.microsoft)$/' => ['ai', 'Copilot'],
        '/(^|\.)gemini\.google\.com$/' => ['ai', 'Gemini'],
        '/(^|\.)(alice\.yandex\.ru|ya\.ru)$/' => ['ai', 'Alice (Yandex)'],
        '/(^|\.)(chat\.deepseek\.com|deepseek\.com)$/' => ['ai', 'DeepSeek'],
        '/(^|\.)(grok\.com|meta\.ai|you\.com|poe\.com|chat\.mistral\.ai)$/' => ['ai', 'Other AI'],
        '/(^|\.)google\.[a-z.]+$/' => ['search', 'Google'],
        '/(^|\.)yandex\.[a-z.]+$/' => ['search', 'Yandex'],
        '/(^|\.)bing\.com$/' => ['search', 'Bing'],
        '/(^|\.)(duckduckgo\.com|search\.yahoo\.com|go\.mail\.ru|baidu\.com|ecosia\.org)$/' => ['search', 'Other search'],
        '/(^|\.)(t\.me|telegram\.org|web\.telegram\.org)$/' => ['social', 'Telegram'],
        '/(^|\.)(facebook\.com|fb\.com|l\.facebook\.com|m\.facebook\.com)$/' => ['social', 'Facebook'],
        '/(^|\.)(instagram\.com|l\.instagram\.com)$/' => ['social', 'Instagram'],
        '/(^|\.)(linkedin\.com|lnkd\.in)$/' => ['social', 'LinkedIn'],
        '/(^|\.)(youtube\.com|youtu\.be)$/' => ['social', 'YouTube'],
        '/(^|\.)(vk\.com|ok\.ru|tiktok\.com|x\.com|twitter\.com|t\.co)$/' => ['social', 'Other social'],
    ];

    public function prunable(): Builder
    {
        return static::where('visited_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** Build a row from the request (not saved). */
    public static function fromRequest(Request $request): self
    {
        $ua = (string) $request->userAgent();
        $bot = self::botName($ua);
        $refHost = strtolower((string) parse_url((string) $request->headers->get('referer'), PHP_URL_HOST));
        $refHost = preg_replace('/^www\./', '', $refHost);
        $ownHost = preg_replace('/^www\./', '', strtolower($request->getHost()));
        $publicHost = preg_replace('/^www\./', '', strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        $source = 'direct';
        if ($refHost !== '') {
            $source = in_array($refHost, [$ownHost, $publicHost], true) ? 'internal' : (self::referrer($refHost)[0] ?? 'other');
        }
        $utm = $request->query('utm_source');

        return new static([
            'visited_at' => now(),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
            'route' => mb_substr((string) $request->route()?->getName(), 0, 64) ?: null,
            'locale' => in_array($request->route('locale'), ['uz', 'ru', 'en'], true) ? $request->route('locale') : null,
            'referrer_host' => $refHost !== '' && $source !== 'internal' ? mb_substr($refHost, 0, 120) : null,
            'source' => $bot ? 'direct' : $source,
            'utm_source' => is_string($utm) ? mb_substr(Str::lower($utm), 0, 64) : null,
            'device' => $bot ? null : self::device($ua),
            'visitor_hash' => substr(hash('sha256', config('app.key').'|'.now()->toDateString().'|'.$request->ip().'|'.$ua), 0, 16),
            'is_bot' => $bot !== null,
            'bot' => $bot,
        ]);
    }

    /** @return array{0: string, 1: string}|null [source, name] */
    public static function referrer(string $host): ?array
    {
        foreach (self::REFERRERS as $pattern => $info) {
            if (preg_match($pattern, $host)) {
                return $info;
            }
        }

        return null;
    }

    /** The crawler's name (one of SeoSetting::CRAWLERS, else "Other bot"), or null for a person. */
    public static function botName(string $ua): ?string
    {
        foreach (array_keys(SeoSetting::CRAWLERS) as $bot) {
            if (stripos($ua, $bot) !== false) {
                return $bot;
            }
        }
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|fetch|preview|headless|python-|curl\/|wget|httpclient|okhttp|go-http|java\//i', $ua)) {
            return 'Other bot';
        }

        return null;
    }

    private static function device(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk/i', $ua) => 'tablet',
            (bool) preg_match('/mobi|android|iphone|ipod|opera mini|iemobile/i', $ua) => 'mobile',
            default => 'desktop',
        };
    }
}
