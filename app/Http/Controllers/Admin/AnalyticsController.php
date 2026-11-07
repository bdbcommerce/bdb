<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        ]);
    }
}
