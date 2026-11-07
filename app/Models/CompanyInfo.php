<?php

namespace App\Models;

use App\Models\Concerns\FlushesChatKnowledge;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class CompanyInfo extends Model
{
    use FlushesChatKnowledge, HasTranslations;

    protected $table = 'company_info';

    public array $translatable = ['address', 'slogan', 'about'];

    protected $fillable = [
        'address', 'slogan', 'about',
        'phone', 'email',
        'telegram', 'instagram', 'facebook', 'youtube', 'linkedin',
        'map_embed', 'google_analytics', 'yandex_metrika',
        'shop_url',
    ];

    /**
     * Social networks: label, accepted hosts (regex), how a bare @handle becomes a URL and what a
     * handle may look like. Admins may type a full link, a link without https://, or just a handle.
     */
    public const SOCIALS = [
        'telegram' => [
            'label' => 'Telegram', 'hosts' => '/^(t\.me|telegram\.me)$/',
            'handle' => 'https://t.me/%s', 'handle_pattern' => '/^[A-Za-z0-9_]{5,32}$/',
            'example' => 'https://t.me/bdb_uz or @bdb_uz',
        ],
        'instagram' => [
            'label' => 'Instagram', 'hosts' => '/^((www\.)?instagram\.com|instagr\.am)$/',
            'handle' => 'https://www.instagram.com/%s', 'handle_pattern' => '/^[A-Za-z0-9._]{1,30}$/',
            'example' => 'https://www.instagram.com/bdb.uz or @bdb.uz',
        ],
        'facebook' => [
            'label' => 'Facebook', 'hosts' => '/^(((www|m|web|mbasic|business)\.)?facebook\.com|fb\.com|fb\.me)$/',
            'handle' => 'https://www.facebook.com/%s', 'handle_pattern' => '/^[A-Za-z0-9.-]{2,50}$/',
            'example' => 'https://www.facebook.com/bdb.uz',
        ],
        'linkedin' => [
            'label' => 'LinkedIn', 'hosts' => '/^(((www|[a-z]{2})\.)?linkedin\.com|lnkd\.in)$/',
            'handle' => 'https://www.linkedin.com/company/%s', 'handle_pattern' => '/^[A-Za-z0-9_-]{2,100}$/',
            'example' => 'https://www.linkedin.com/company/bdb-uz (a username means a company page)',
        ],
        'youtube' => [
            'label' => 'YouTube', 'hosts' => '/^(((www|m|music)\.)?youtube\.com|youtu\.be)$/',
            'handle' => 'https://www.youtube.com/@%s', 'handle_pattern' => '/^[A-Za-z0-9._-]{3,30}$/',
            'example' => 'https://www.youtube.com/@bdbuz or @bdbuz',
        ],
    ];

    /** Longest URL that fits the database column. */
    public const SOCIAL_MAX = 255;

    public static function instance(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /**
     * A safe https URL for the network, or null when the value is empty or not a link to that network.
     * "@bdb_uz" / "bdb_uz" → handle URL; "t.me/bdb_uz" → "https://t.me/bdb_uz".
     */
    public static function socialUrl(string $network, ?string $value): ?string
    {
        $config = self::SOCIALS[$network] ?? null;
        $value = trim((string) $value);
        if (! $config || $value === '') {
            return null;
        }

        // Control characters, spaces, quotes (single and double), angle brackets and backslashes are never part of a valid link.
        if (preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $value)) {
            return null;
        }

        // A handle: "@bdb.uz", or "bdb_uz" without dots or slashes (with a dot it could be a domain).
        if (str_starts_with($value, '@') || ! preg_match('#[./:]#', $value)) {
            $handle = substr($value, str_starts_with($value, '@') ? 1 : 0);

            return preg_match($config['handle_pattern'], $handle) ? sprintf($config['handle'], $handle) : null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! preg_match($config['hosts'], $host)
            || isset($parts['user']) || isset($parts['pass'])
            // Must point at a page or profile, not just the network's home page.
            || trim($path, '/') === '') {
            return null;
        }

        $url = 'https://'.$host.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return strlen($url) <= self::SOCIAL_MAX ? $url : null;
    }

    /** A public http(s) shop link with a dotted host (also used by the admin validation). */
    public static function isValidShopUrl(?string $url): bool
    {
        return (bool) preg_match('#^https?://[^\s/?\#:@]+\.[^\s/?\#:@]+([:/?\#]\S*)?$#i', trim((string) $url));
    }

    /**
     * The online shop link (default https://mylabel.uz) with UTM tags so the shop's analytics can
     * tell which block a visitor came from; null when no valid http(s) link is set.
     */
    public function shopUrl(string $placement = 'site'): ?string
    {
        $url = trim((string) $this->shop_url);
        if (! self::isValidShopUrl($url)) {
            return null;
        }

        // UTM tags go in the query, before any #fragment.
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $utm = http_build_query(['utm_source' => 'bdb_site', 'utm_medium' => 'referral', 'utm_campaign' => $placement]);

        return $base.(str_contains($base, '?') ? '&' : '?').$utm.($fragment !== null ? '#'.$fragment : '');
    }

    /** The shop's host for display, e.g. "mylabel.uz". */
    public function shopHost(): ?string
    {
        $host = parse_url(trim((string) $this->shop_url), PHP_URL_HOST);

        return $host ? preg_replace('/^www\./i', '', $host) : null;
    }

    /** @return array<string, string> label => URL, only the valid ones */
    public function socialLinks(): array
    {
        $links = [];
        foreach (self::SOCIALS as $network => $config) {
            if ($url = self::socialUrl($network, $this->{$network})) {
                $links[$config['label']] = $url;
            }
        }

        return $links;
    }
}
