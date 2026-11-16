<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serves the public home page from a cached copy (per language) for up to TTL seconds.
 * Rebuilt on the first visit after it expires, after content saves (see AppServiceProvider)
 * and on deploy (`php artisan optimize:clear` clears the cache).
 * Skipped for signed-in users (admins: theme preview), unknown query strings and flashed session data.
 */
class CacheHomePage
{
    public const TTL = 6 * 3600;

    public const LOCALES = ['uz', 'ru', 'en'];

    /** Stands in for the visitor's CSRF token in the cached HTML. */
    private const TOKEN = '__HOME_CSRF_TOKEN__';

    /** Tracking parameters that don't change the page. */
    private const IGNORED_QUERY = ['gclid', 'fbclid', 'yclid', 'ysclid', '_openstat'];

    private const VERSION_KEY = 'site.home.page.version';

    /**
     * One copy per language, scheme + host (links, canonical and asset URLs are absolute) and
     * front-end build (a new `npm run build` changes the asset file names). flush() bumps the version.
     */
    public static function key(string $locale, ?string $root = null): string
    {
        $root ??= request()->getSchemeAndHttpHost();
        $manifest = public_path('build/manifest.json');
        $build = is_file($manifest) ? (string) filemtime($manifest) : '-';
        try {
            $version = (int) Cache::get(self::VERSION_KEY, 0);
        } catch (Throwable) {
            $version = 0;
        }

        return 'site.home.page.'.$version.'.'.$locale.'.'.md5($root.'|'.$build);
    }

    public static function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
        } catch (Throwable) {
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->cacheable($request)) {
            return $next($request);
        }

        $key = self::key(app()->getLocale());
        try {
            $html = Cache::get($key);
        } catch (Throwable) {
            $html = null;
        }
        if (is_string($html) && $html !== '') {
            return response(str_replace(self::TOKEN, csrf_token(), $html))
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('X-Home-Cache', 'hit');
        }

        $response = $next($request);

        $content = $response->getContent();
        if ($response->getStatusCode() === 200 && is_string($content) && $content !== ''
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            try {
                // Key again: rendering may have created default settings rows, which bumps the version.
                Cache::put(self::key(app()->getLocale()), str_replace(csrf_token(), self::TOKEN, $content), self::TTL);
            } catch (Throwable) {
                // A cache problem must never break the page.
            }
            $response->headers->set('X-Home-Cache', 'miss');
        }

        return $response;
    }

    private function cacheable(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->user() || Vite::isRunningHot()) { // hot: dev-server URLs
            return false;
        }
        foreach (array_keys($request->query()) as $param) {
            if (! str_starts_with((string) $param, 'utm_') && ! in_array($param, self::IGNORED_QUERY, true)) {
                return false; // e.g. ?theme_preview=…
            }
        }
        $session = $request->hasSession() ? $request->session() : null;

        return ! $session || ! ($session->has('errors') || $session->has('success') || $session->has('theme_preview') || $session->hasOldInput());
    }
}
