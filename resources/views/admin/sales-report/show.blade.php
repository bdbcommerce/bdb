@extends('admin.layout')
@section('title', 'Sales report')
@section('heading', ($report->period === 'day' ? 'Daily' : 'Weekly').' sales report — '.$report->period_end->copy()->setTimezone('Asia/Tashkent')->format('d.m.Y'))

@section('content')
@php
    $m = (array) $report->metrics;
    $p = (array) $previous?->metrics;
    $tz = 'Asia/Tashkent';
    $time = fn (int $s) => ($min = (int) round($s / 60)) < 60 ? max(1, $min).' min' : round($min / 60, 1).' h';
    $sections = [
        'ai' => ['AI team', ['chats' => 'Chats the AI answered', 'replies' => 'AI replies', 'handovers' => 'Hand-overs to a colleague', 'leads' => 'Requests (bot + Premium)']],
        'orders' => ['Orders', ['confirmed' => 'Confirmed', 'confirmed_sum' => 'Confirmed, sum', 'drafts' => 'New drafts', 'cancelled' => 'Cancelled']],
        'companies' => ['Companies', ['linked_by_ai' => 'Linked by the AI (INN)', 'linked_by_manager' => 'Linked by managers', 'new_clients' => 'New companies']],
        'managers' => ['Managers', ['leads_taken' => 'Requests taken', 'leads_not_taken' => 'Requests not taken', 'waiting_over_hour' => 'Chats waiting over 1 h at the end']],
        'off_hours' => ['Off hours', ['messages' => 'Customer messages', 'customers' => 'Customers', 'answered_by_ai' => 'Answered by the AI']],
    ];
    // Lower is better for these.
    $worse = ['cancelled', 'leads_not_taken', 'waiting_over_hour'];
@endphp
<div class="w-full space-y-4">
    <a href="{{ route('admin.sales-report.index') }}" class="text-sm underline text-slate-600">← All reports</a>
    <p class="text-sm text-slate-500">{{ $report->period_start->copy()->setTimezone($tz)->format('d.m.Y H:i') }} – {{ $report->period_end->copy()->setTimezone($tz)->format('d.m.Y H:i') }} · {{ $report->conversations }} conversations · {{ $report->status }} · {{ \App\Services\Invoices\ClaudePricing::format($report->cost_usd) }}{{ $report->chat_id ? ' · sent to '.$report->chat_id : '' }}</p>

    @if ($m)
        <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-5" data-metrics>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Numbers</h2>
                <p class="text-xs text-slate-400">Counted from the database.
                    @if ($previous) Change against the report of {{ $previous->period_start->copy()->setTimezone($tz)->format('d.m') }} – {{ $previous->period_end->copy()->setTimezone($tz)->format('d.m.Y') }}. @else No earlier report to compare with. @endif</p>
            </div>
            @foreach ($sections as $key => [$title, $fields])
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 mb-2">{{ $title }}</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach ($fields as $field => $label)
                            @php
                                $now = (int) ($m[$key][$field] ?? 0);
                                $was = $previous ? (int) ($p[$key][$field] ?? 0) : null;
                                $diff = $was === null ? null : $now - $was;
                                $good = $diff !== null && $diff !== 0 && (($diff > 0) xor in_array($field, $worse, true));
                            @endphp
                            <div class="border border-slate-200 rounded-lg px-3 py-2">
                                <p class="text-xs text-slate-500">{{ $label }}</p>
                                <p class="text-lg font-semibold text-slate-900">{{ number_format($now, 0, '', ' ') }}@if ($field === 'confirmed_sum')<span class="text-xs font-normal text-slate-500"> sum</span>@endif</p>
                                @if ($diff !== null)
                                    <p class="text-xs {{ $diff === 0 ? 'text-slate-400' : ($good ? 'text-emerald-700' : 'text-red-700') }}">
                                        {{ $diff === 0 ? 'no change' : ($diff > 0 ? '▲ +' : '▼ ').number_format($diff, 0, '', ' ') }}{{ $diff !== 0 && $was ? ' ('.($diff > 0 ? '+' : '').round($diff / $was * 100).'%)' : '' }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if ($key === 'ai')
                        @php $shadow = (array) ($m['ai']['shadow'] ?? []); $ex = (array) ($m['ai']['experts'] ?? []); @endphp
                        <p class="text-xs text-slate-500 mt-2">
                            Shadow drafts: {{ array_sum($shadow) }}@if (array_sum($shadow)) ({{ collect(\App\Models\AiShadowDraft::STATUSES)->map(fn ($name, $s) => ($shadow[$s] ?? 0) ? $name.' '.$shadow[$s] : null)->filter()->implode(', ') }})@endif
                            · Expert questions: {{ (int) ($ex['asked'] ?? 0) }}, answered {{ (int) ($ex['answered'] ?? 0) }}@if (($ex['avg_answer_minutes'] ?? null) !== null), on average in {{ $time((int) $ex['avg_answer_minutes'] * 60) }}@endif
                        </p>
                    @elseif ($key === 'orders' && ($m['orders']['top_products'] ?? []))
                        <p class="text-xs text-slate-500 mt-2">Most ordered: {{ collect($m['orders']['top_products'])->map(fn ($n, $name) => $name.' ×'.$n)->implode(', ') }}</p>
                    @elseif ($key === 'managers')
                        @if ($m['managers']['people'] ?? [])
                            <div class="overflow-x-auto mt-3">
                                <table class="w-full text-sm">
                                    <thead class="text-left text-xs text-slate-500"><tr><th class="py-1 pr-3 font-medium">Person</th><th class="py-1 pr-3 font-medium">Replies timed</th><th class="py-1 pr-3 font-medium">First reply, average</th><th class="py-1 font-medium">Median</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($m['managers']['people'] as $person)
                                            <tr><td class="py-1 pr-3 text-slate-900">{{ $person['name'] ?? '' }}</td><td class="py-1 pr-3">{{ (int) ($person['replies'] ?? 0) }}</td><td class="py-1 pr-3">{{ $time((int) ($person['avg_seconds'] ?? 0)) }}</td><td class="py-1">{{ $time((int) ($person['median_seconds'] ?? 0)) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if ($m['managers']['taken_by'] ?? [])
                            <p class="text-xs text-slate-500 mt-2">Took requests (“I take it”): {{ collect($m['managers']['taken_by'])->map(fn ($n, $who) => $who.' '.$n)->implode(', ') }}</p>
                        @endif
                    @elseif ($key === 'off_hours' && (($m['off_hours']['holiday_messages'] ?? 0) || ($m['off_hours']['notes'] ?? 0)))
                        <p class="text-xs text-slate-500 mt-2">On holidays: {{ (int) ($m['off_hours']['holiday_messages'] ?? 0) }} messages · off-hours notes sent: {{ (int) ($m['off_hours']['notes'] ?? 0) }}</p>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    {{-- The Telegram HTML: every text was escaped when it was built; safeMessages() keeps only plain tags and http(s) links. --}}
    @if ($report->html)
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide pt-2">Messages sent</h2>
    @endif
    @foreach ($report->safeMessages() as $message)
        <div class="bg-white border border-slate-200 rounded-xl p-5 text-sm text-slate-800 whitespace-pre-line break-words [&_a]:underline" data-message>{!! $message !!}</div>
    @endforeach
</div>
@endsection
