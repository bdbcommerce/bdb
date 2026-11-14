<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\LinkClick;
use App\Models\PageView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

use function Illuminate\Support\defer;

/**
 * /go/brand/{brand}/{target}: counts a click on a brand's official website or shop link, then redirects there.
 * Only to the URL saved for that brand (no open redirect). Admins, crawlers, link previews and prerenders are
 * not counted; one visitor counts once per brand, link and day, and at most DAILY_CAP clicks are stored a day.
 */
class BrandLinkController extends Controller
{
    public const DAILY_CAP = 5000;

    public function __invoke(Request $request, Brand $brand, string $target)
    {
        abort_unless($brand->is_active, 404);
        $url = match ($target) {
            'website' => $brand->websiteUrl(),
            'shop' => $brand->shopUrl('brand_card'),
            default => null,
        };
        abort_unless($url, 404);

        if ($this->countable($request)) {
            $view = PageView::fromRequest($request); // device + daily visitor hash, as for page views
            $click = [
                'brand_id' => $brand->id,
                'brand_name' => mb_substr((string) $brand->getTranslation('name', 'en'), 0, 200),
                'target' => $target,
                'locale' => in_array($request->query('l'), ['uz', 'ru', 'en'], true) ? $request->query('l') : null,
                'device' => $view->device,
                'visitor_hash' => $view->visitor_hash,
                'clicked_at' => now(),
            ];
            defer(function () use ($click) {
                try {
                    $day = now()->toDateString();
                    // Repeated clicks by the same visitor on the same link today are one click.
                    if (! Cache::add("link-click:{$day}:{$click['visitor_hash']}:{$click['brand_id']}:{$click['target']}", 1, now()->endOfDay())) {
                        return;
                    }
                    Cache::add("link-clicks:{$day}", 0, now()->endOfDay());
                    if (Cache::increment("link-clicks:{$day}") > self::DAILY_CAP) {
                        return; // a flood from many addresses can't grow the table without limit
                    }
                    LinkClick::create($click);
                } catch (Throwable) {
                    // Counting must never stop the visitor (e.g. table not migrated yet).
                }
            });
        }

        return redirect()->away($url)->withHeaders(['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store']);
    }

    /** A person clicked: not an admin, a crawler / link preview, or a browser prefetch / prerender. */
    private function countable(Request $request): bool
    {
        $purpose = strtolower((string) $request->headers->get('Purpose', $request->headers->get('Sec-Purpose')));

        return ! $request->user()?->is_admin
            && ! preg_match('/prefetch|prerender|preview/', $purpose)
            && PageView::botName((string) $request->userAgent()) === null;
    }
}
