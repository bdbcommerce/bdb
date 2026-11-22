@extends('admin.layout')
@section('title', 'Sales report')
@section('heading', 'Telegram — Sales reports')

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $channels = old('channels', $settings->channelList());
    $badge = ['sent' => 'bg-emerald-50 text-emerald-700', 'empty' => 'bg-slate-100 text-slate-600', 'failed' => 'bg-red-50 text-red-700'];
@endphp

@section('content')
<div class="w-full space-y-6">
    <div class="bg-white border border-slate-200 rounded-xl p-6 text-sm text-slate-600 space-y-2">
        <p><b class="text-slate-900">How it works.</b> Every <b>Saturday at 09:00 (Tashkent)</b> the last 7 days of Telegram conversations go to <b>Claude Opus 5.5</b>. It decides for each conversation whether it was a lead, an order or a support question, whether it was won, is still open or was lost, and rates how our side handled it (1–10: reply speed, a clear price or offer, a next step, follow-up). Reply times are measured by the system, not guessed.</p>
        <p>Each report has <b>one message per conversation</b> (greetings and spam together in one), then the <b>summary</b>: totals, conversion, team rating, what went well, problems, questions the bot could not answer, and a goal for next week. Phone numbers are hidden before anything is sent to Claude.</p>
        <p><b>Daily report:</b> every day at <b>18:00 (Tashkent)</b> the same analysis of the last 24 hours goes to the <b>JSON recipient</b> of the invoice parser (your Telegram user ID) — not to the group.</p>
        <p>The weekly report goes <b>only to the Telegram group</b> set as the source chat in <a href="{{ route('admin.invoice-parser.edit') }}" class="underline">Invoice parser → Settings</a> — nowhere else. A failed Saturday run alerts the admin alerts chat, like every scheduled task; a failed “Send now” shows its error in the list below.</p>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 text-sm" data-status>
        <div>
            <p class="text-xs text-slate-500 uppercase tracking-wide">Telegram group</p>
            @if ($chatId)
                <p class="font-mono text-slate-900 mt-1">{{ $chatId }}</p>
            @else
                <p class="text-amber-700 mt-1">Not set{{ filled($sourceChatId) ? ' — the source chat '.$sourceChatId.' is not a group ID' : '' }}. Set the source chat ID (starts with -100) in Invoice parser → Settings.</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500 uppercase tracking-wide">Daily report recipient</p>
            @if ($dailyChatId)
                <p class="font-mono text-slate-900 mt-1">{{ $dailyChatId }}</p>
                <p class="text-xs text-slate-500 mt-1">Next: {{ $settings->daily_enabled && $nextDaily ? $nextDaily->copy()->setTimezone('Asia/Tashkent')->format('D d.m H:i') : 'switched off' }}</p>
            @else
                <p class="text-amber-700 mt-1">Not set. Fill in the JSON recipient (your Telegram user ID) in Invoice parser → Settings.</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500 uppercase tracking-wide">Claude key</p>
            <p class="mt-1 {{ $hasKey ? 'text-slate-900' : 'text-amber-700' }}">{{ $hasKey ? 'Set (ANTHROPIC_API_KEY)' : 'Missing: add ANTHROPIC_API_KEY to .env' }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500 uppercase tracking-wide">Next weekly report</p>
            <p class="text-slate-900 mt-1">{{ $settings->enabled && $nextRun ? $nextRun->copy()->setTimezone('Asia/Tashkent')->format('D d.m.Y H:i').' (Tashkent)' : 'Switched off' }}</p>
            @if ($lastRun)
                <p class="text-xs text-slate-500 mt-1">Last run: {{ $lastRun->started_at->copy()->setTimezone('Asia/Tashkent')->format('d.m.Y H:i') }} — {{ $lastRun->state() }}</p>
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.sales-report.update') }}" class="bg-white border border-slate-200 rounded-xl p-6 space-y-5">
        @csrf @method('PUT')
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="language" class="block text-sm font-medium text-slate-700 mb-1">Report language</label>
                <select id="language" name="language" class="{{ $input }}">
                    @foreach (\App\Models\SalesReportSetting::LANGUAGES as $code => $name)
                        <option value="{{ $code }}" @selected(old('language', $settings->languageCode()) === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset>
                <legend class="block text-sm font-medium text-slate-700 mb-1">Chats to analyse</legend>
                @foreach (\App\Models\SalesReportSetting::CHANNELS as $key => $label)
                    <label class="flex items-center gap-2 text-sm text-slate-700 py-0.5">
                        <input type="checkbox" name="channels[]" value="{{ $key }}" @checked(in_array($key, (array) $channels, true)) class="rounded border-slate-300"> {{ $label }}
                    </label>
                @endforeach
                @error('channels')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </fieldset>
        </div>
        <div>
            <label for="instructions" class="block text-sm font-medium text-slate-700 mb-1">Notes for Claude (optional)</label>
            <textarea id="instructions" name="instructions" rows="4" maxlength="3000" class="{{ $input }}" placeholder="e.g. Aziz and Dilnoza are sales managers. We aim to answer within 15 minutes during 09:00–18:00. Prices are sent as PDF offers.">{{ old('instructions', $settings->instructions) }}</textarea>
            <p class="text-xs text-slate-400 mt-1">Context about the team and targets. It cannot change the report format.</p>
            @error('instructions')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled)) class="rounded border-slate-300">
                Send the weekly report every Saturday
            </label>
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" name="daily_enabled" value="1" @checked(old('daily_enabled', $settings->daily_enabled)) class="rounded border-slate-300">
                Send the daily report at 18:00
            </label>
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save settings</button>
        </div>
    </form>

    <div class="bg-white border border-slate-200 rounded-xl p-6 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-slate-600">
            <p>Send a report now (a paid Claude request, usually well under $1).</p>
            @error('run')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.sales-report.run') }}" onsubmit="return confirm('Analyse the last 24 hours now and send the report to the JSON recipient? This is a paid Claude request.')">
                @csrf <input type="hidden" name="period" value="day">
                <button type="submit" class="text-sm font-medium rounded-lg px-4 py-2 border border-slate-300 hover:border-slate-500">Send daily now</button>
            </form>
            <form method="POST" action="{{ route('admin.sales-report.run') }}" onsubmit="return confirm('Analyse the last 7 days now and post the report to the Telegram group? This is a paid Claude request.')">
                @csrf <input type="hidden" name="period" value="week">
                <button type="submit" class="text-sm font-medium rounded-lg px-4 py-2 border border-slate-300 hover:border-slate-500">Send weekly now</button>
            </form>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <h2 class="px-6 pt-5 pb-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Reports</h2>
        @if ($reports->isEmpty())
            <p class="px-6 pb-6 text-sm text-slate-500">No reports yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs text-slate-500 uppercase tracking-wide">
                        <tr><th class="px-6 py-2">Period (Tashkent)</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Chats</th><th class="px-3 py-2">Messages</th><th class="px-3 py-2">Cost</th><th class="px-3 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($reports as $report)
                            <tr data-report="{{ $report->id }}">
                                <td class="px-6 py-2 whitespace-nowrap">{{ $report->period_start->copy()->setTimezone('Asia/Tashkent')->format('d.m H:i') }} – {{ $report->period_end->copy()->setTimezone('Asia/Tashkent')->format('d.m.Y H:i') }}<span class="text-xs text-slate-400"> · {{ $report->period === 'day' ? 'daily' : 'weekly' }} · {{ $report->trigger }}</span></td>
                                <td class="px-3 py-2"><span class="text-xs rounded px-2 py-0.5 {{ $badge[$report->status] ?? '' }}">{{ $report->status }}</span>
                                    @if ($report->error)<p class="text-xs text-red-600 mt-1 max-w-md">{{ \Illuminate\Support\Str::limit($report->error, 160) }}</p>@endif</td>
                                <td class="px-3 py-2">{{ $report->conversations }}</td>
                                <td class="px-3 py-2">{{ count(array_filter((array) $report->message_ids)) }}</td>
                                <td class="px-3 py-2">{{ \App\Services\Invoices\ClaudePricing::format($report->cost_usd) }}</td>
                                <td class="px-3 py-2 text-right">@if ($report->html)<a href="{{ route('admin.sales-report.show', $report) }}" class="underline text-slate-700">View</a>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-6 py-3">{{ $reports->links() }}</div>
        @endif
    </div>
</div>
@endsection
