@extends('admin.layout')
@section('title', 'Site analytics')
@section('heading', 'Site analytics')

@php
    $card = 'bg-white border border-slate-200 rounded-xl p-5';
    $h2 = 'text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3';
    $tab = fn (bool $active) => 'text-xs font-medium rounded-md px-3 py-1.5 transition-colors whitespace-nowrap '
        .($active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
@endphp

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500 max-w-xl">Public page views on this site. No IP addresses or cookies are stored; visitors are counted per day. Kept {{ $keepDays }} days. Admins' own visits are not counted.</p>
    <nav class="flex flex-wrap gap-1 bg-slate-100 rounded-lg p-1" aria-label="Period">
        @foreach($periods as $d => $label)
            <a href="{{ route('admin.analytics', ['days' => $d]) }}" @if($days === $d) aria-current="true" @endif class="{{ $tab($days === $d) }}">{{ $label }}</a>
        @endforeach
    </nav>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach([
        ['Page views', $totals['views'], 'by people'],
        ['Visitors', $totals['visitors'], 'unique per day'],
        ['From AI assistants', $totals['ai'], 'ChatGPT, Perplexity, Claude, Copilot…'],
        ['Crawler visits', $totals['crawls'], 'search engines and AI bots'],
    ] as [$label, $value, $hint])
        <div class="{{ $card }}">
            <p class="text-2xl font-bold text-slate-900 tabular-nums">{{ number_format($value, 0, '.', ' ') }}</p>
            <p class="text-sm font-medium text-slate-700 mt-1">{{ $label }}</p>
            <p class="text-xs text-slate-400 mt-0.5">{{ $hint }}</p>
        </div>
    @endforeach
</div>

{{-- Daily views --}}
<div class="{{ $card }} mb-6">
    <h2 class="{{ $h2 }}">Page views per day</h2>
    <div class="flex items-end gap-px h-40 overflow-x-auto" role="img" aria-label="Page views per day">
        @foreach($chart as $point)
            <div class="flex-1 min-w-1.5 h-full flex items-end" title="{{ $point['label'] }}: {{ $point['views'] }}">
                <div class="w-full rounded-t-sm {{ $point['views'] ? 'bg-slate-800' : 'bg-slate-100' }}" style="height: {{ max(2, round($point['views'] / $max * 100)) }}%"></div>
            </div>
        @endforeach
    </div>
    <div class="flex justify-between text-[11px] text-slate-400 mt-1">
        <span>{{ $chart->first()['label'] }}</span><span>{{ $chart->last()['label'] }}</span>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    @foreach([
        ['Top pages', $pages->map(fn ($n) => ['n' => $n]), fn ($k) => $k, 'No page views yet.'],
        ['Traffic sources', $sources->map(fn ($n) => ['n' => $n]), fn ($k) => $sourceLabels[$k] ?? $k, 'No visits yet.'],
        ['AI assistants that sent visitors', $ai, fn ($k, $r) => ($r['name'] ?? $k).' · '.$k, 'No visits from AI assistants yet. They come once ChatGPT, Perplexity or Copilot start citing the site.'],
        ['Referring sites', $referrers, fn ($k, $r) => $r['name'] ? $r['name'].' · '.$k : $k, 'No referring sites yet.'],
        ['Crawlers (search engines and AI bots)', $bots->map(fn ($n) => ['n' => $n]), fn ($k) => $k.(isset($botLast[$k]) ? ' · last '.$botLast[$k]->diffForHumans() : ''), 'No crawler visits yet.'],
        ['Languages', $locales->map(fn ($n) => ['n' => $n]), fn ($k) => strtoupper($k), 'No data yet.'],
        ['Devices', $devices->map(fn ($n) => ['n' => $n]), fn ($k) => ucfirst($k), 'No data yet.'],
        ['Campaigns (utm_source)', $utm->map(fn ($n) => ['n' => $n]), fn ($k) => $k, 'No tagged links used yet.'],
    ] as [$title, $rows, $name, $empty])
        @php $rowMax = max(1, (int) collect($rows)->max('n')); @endphp
        <div class="{{ $card }}">
            <h2 class="{{ $h2 }}">{{ $title }}</h2>
            @if(collect($rows)->isEmpty())
                <p class="text-sm text-slate-400">{{ $empty }}</p>
            @else
                <ul class="space-y-2">
                    @foreach($rows as $key => $row)
                        <li class="text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-slate-700" title="{{ $key }}">{{ $name($key, $row) }}</span>
                                <span class="shrink-0 tabular-nums font-medium text-slate-900">{{ $row['n'] }}</span>
                            </div>
                            <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-slate-700" style="width: {{ round($row['n'] / $rowMax * 100) }}%"></div></div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endforeach
</div>

<div class="{{ $card }} mt-6" id="brand-clicks">
    <h2 class="{{ $h2 }}">Brand link clicks</h2>
    <p class="text-xs text-slate-500 mb-3">
        Clicks on the “Website” and “Buy online” links on the Brands page ({{ number_format($brandsPageViews) }} page views in this period).
        A person counts once per brand and link per day (repeat clicks are ignored); Visitors = different people per day. Bots, link previews and admins are not counted.
    </p>
    @if($brandClicks->isEmpty())
        <p class="text-sm text-slate-400">No brands with a website or shop link.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400 text-left">
                    <tr>
                        <th class="py-2 pr-4 font-medium">Brand</th>
                        <th class="py-2 pr-4 font-medium text-right">Website clicks</th>
                        <th class="py-2 pr-4 font-medium text-right">Visitors</th>
                        <th class="py-2 pr-4 font-medium text-right">Buy online clicks</th>
                        <th class="py-2 font-medium text-right">Visitors</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 tabular-nums">
                    @foreach($brandClicks as $row)
                        <tr>
                            <td class="py-2 pr-4 text-slate-700">{{ $row['name'] }}</td>
                            <td class="py-2 pr-4 text-right {{ $row['website'] || $row['website_clicks'] ? 'text-slate-900 font-medium' : 'text-slate-300' }}">{{ $row['website'] || $row['website_clicks'] ? $row['website_clicks'] : '—' }}</td>
                            <td class="py-2 pr-4 text-right text-slate-500">{{ $row['website'] || $row['website_clicks'] ? $row['website_visitors'] : '' }}</td>
                            <td class="py-2 pr-4 text-right {{ $row['shop'] || $row['shop_clicks'] ? 'text-slate-900 font-medium' : 'text-slate-300' }}">{{ $row['shop'] || $row['shop_clicks'] ? $row['shop_clicks'] : '—' }}</td>
                            <td class="py-2 text-right text-slate-500">{{ $row['shop'] || $row['shop_clicks'] ? $row['shop_visitors'] : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="tabular-nums border-t border-slate-200">
                    <tr>
                        <td class="py-2 pr-4 text-xs font-semibold text-slate-500 uppercase">Total</td>
                        {{-- Visitors are not added up: one person clicking two brands would count twice. --}}
                        <td class="py-2 pr-4 text-right font-semibold">{{ $brandClicks->sum('website_clicks') }}</td>
                        <td class="py-2 pr-4"></td>
                        <td class="py-2 pr-4 text-right font-semibold">{{ $brandClicks->sum('shop_clicks') }}</td>
                        <td class="py-2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-xs text-slate-400 mt-3">“—” = the brand has no such link. To remove a link, clear the Website or Online shop field in <a href="{{ route('admin.brands.index') }}" class="underline">Brands</a>.</p>
    @endif
</div>

<p class="text-xs text-slate-400 mt-6">
    Google Analytics and Yandex Metrica codes can still be added in <a href="{{ route('admin.company-info.edit') }}" class="underline">Profile &amp; SEO</a> for deeper reports.
    Crawler access is set in <a href="{{ route('admin.seo.edit') }}" class="underline">SEO &amp; AI visibility</a>.
</p>
@endsection
