<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\LinkClick;
use App\Models\PageView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Admin → Site analytics: visits, sources (incl. AI assistants), pages and crawler visits. */
class AnalyticsController extends Controller
{
    public const PERIODS = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];

    public function __invoke(Request $request)
    {
        $days = array_key_exists((int) $request->query('days'), self::PERIODS) ? (int) $request->query('days') : 30;
        $from = now()->subDays($days - 1)->startOfDay();

        $people = PageView::where('is_bot', false)->where('visited_at', '>=', $from);
        $bots = PageView::where('is_bot', true)->where('visited_at', '>=', $from);

        // Views per day (people), every day present even when zero.
        $perDay = (clone $people)->toBase()->selectRaw('date(visited_at) as day, count(*) as views, count(distinct visitor_hash) as visitors')
            ->groupBy('day')->pluck('views', 'day');
        $chart = collect(range(0, $days - 1))->map(fn ($i) => [
            'day' => ($d = $from->copy()->addDays($i))->toDateString(),
            'label' => $d->format('d.m'),
            'views' => (int) ($perDay[$d->toDateString()] ?? 0),
        ]);

        $count = fn ($query, string $column, int $limit = 10) => (clone $query)->toBase()->whereNotNull($column)
            ->selectRaw("{$column} as name, count(*) as n")->groupBy($column)->orderByDesc('n')->limit($limit)->pluck('n', 'name');

        $referrers = $count((clone $people)->whereNotIn('source', ['internal', 'direct']), 'referrer_host', 15)
            ->map(fn ($n, $host) => ['n' => $n, 'name' => PageView::referrer($host)[1] ?? null]);
        $ai = $referrers->filter(fn ($r, $host) => (PageView::referrer($host)[0] ?? null) === 'ai');

        return view('admin.analytics.index', [
            'days' => $days,
            'periods' => self::PERIODS,
            'totals' => [
                'views' => (clone $people)->count(),
                'visitors' => (clone $people)->toBase()->selectRaw('count(distinct visitor_hash) as n')->value('n') ?? 0,
                'ai' => (clone $people)->where('source', 'ai')->count(),
                'crawls' => (clone $bots)->count(),
            ],
            'chart' => $chart,
            'max' => max(1, $chart->max('views')),
            'pages' => $count($people, 'path', 15),
            'sources' => (clone $people)->toBase()->where('source', '!=', 'internal')->selectRaw('source as name, count(*) as n')->groupBy('source')->orderByDesc('n')->pluck('n', 'name'),
            'sourceLabels' => PageView::SOURCES,
            'referrers' => $referrers,
            'ai' => $ai,
            'bots' => $count($bots, 'bot', 25),
            'botLast' => (clone $bots)->toBase()->selectRaw('bot, max(visited_at) as last')->groupBy('bot')->pluck('last', 'bot')
                ->map(fn ($t) => Carbon::parse($t)),
            'locales' => $count($people, 'locale'),
            'devices' => $count($people, 'device'),
            'utm' => $count($people, 'utm_source'),
            'keepDays' => PageView::KEEP_DAYS,
            'brandClicks' => $this->brandClicks($from),
            'brandsPageViews' => (clone $people)->where('route', 'site.brands')->count(),
        ]);
    }

    /**
     * Clicks on each brand's website / shop link in the period (clicks and distinct visitors per day),
     * including brands with links but no clicks, so it's clear which links nobody uses.
     */
    private function brandClicks(Carbon $from): Collection
    {
        $stats = LinkClick::where('clicked_at', '>=', $from)->toBase()
            // visitor_hash already changes every day, so distinct hashes = visitors per day added up.
            ->selectRaw('brand_id, brand_name, target, count(*) as clicks, count(distinct visitor_hash) as visitors')
            ->groupBy('brand_id', 'brand_name', 'target')->get();

        // Active brands with a link (even with no clicks), plus any brand that had clicks (also switched-off ones).
        $clickedIds = $stats->pluck('brand_id')->filter()->unique();
        $row = fn (Brand $b, string $suffix = '') => ['name' => $b->getTranslation('name', 'en').$suffix, 'website' => (bool) $b->websiteUrl(), 'shop' => (bool) $b->shopUrl()];
        $rows = Brand::where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn (Brand $b) => $b->websiteUrl() || $b->shopUrl() || $clickedIds->contains($b->id))
            ->mapWithKeys(fn (Brand $b) => [$b->id => $row($b)])->all();
        foreach (Brand::whereIn('id', $clickedIds)->where('is_active', false)->get() as $b) {
            $rows[$b->id] = $row($b, ' (switched off)');
        }

        foreach ($stats as $s) {
            // A deleted brand (brand_id null) keeps its own row by the name saved with the click.
            $key = $s->brand_id ?? 'deleted-'.$s->brand_name;
            $rows[$key] = ($rows[$key] ?? ['name' => $s->brand_name.' (deleted)', 'website' => false, 'shop' => false]);
            $rows[$key][$s->target.'_clicks'] = ($rows[$key][$s->target.'_clicks'] ?? 0) + (int) $s->clicks;
            $rows[$key][$s->target.'_visitors'] = ($rows[$key][$s->target.'_visitors'] ?? 0) + (int) $s->visitors;
        }

        return collect($rows)->map(fn ($r) => $r + ['website_clicks' => 0, 'website_visitors' => 0, 'shop_clicks' => 0, 'shop_visitors' => 0])
            ->sortByDesc(fn ($r) => $r['website_clicks'] + $r['shop_clicks'])->values();
    }
}
