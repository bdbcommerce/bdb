@extends('admin.layout')
@section('title', 'Scheduled tasks')
@section('heading', 'Scheduled tasks')

@php
    $badge = fn (?string $state) => match ($state) {
        'success' => ['bg-green-50 text-green-700', 'Succeeded'],
        'failed' => ['bg-red-50 text-red-700', 'Failed'],
        'running' => ['bg-sky-50 text-sky-700', 'Running'],
        'stuck' => ['bg-amber-50 text-amber-700', 'Did not finish'],
        default => ['bg-slate-100 text-slate-500', 'Never ran'],
    };
    $when = fn ($at) => $at ? $at->format('d.m.Y H:i') : '—';
    $cronLine = '* * * * * cd '.$basePath.' && php artisan schedule:run >> /dev/null 2>&1';
@endphp

@section('content')
<div class="mb-4 rounded-xl border px-4 py-3 text-sm {{ $schedulerRunning ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-800' }}" data-scheduler="{{ $schedulerRunning ? 'running' : 'stopped' }}">
    @if($schedulerRunning)
        <p><span class="font-medium">The scheduler is running.</span> Cron starts it every minute; last scheduled run {{ $lastSchedulerRun?->diffForHumans() ?? 'just now' }}.</p>
    @else
        <p class="font-medium">The scheduler has not run in the last few minutes{{ $lastSchedulerRun ? ' (last run '.$lastSchedulerRun->diffForHumans().')' : '' }}.</p>
        <p class="mt-1">The tasks below run only when the server's cron calls Laravel every minute. Add this line in the hosting panel → Cron (or <code>crontab -e</code>); if the panel asks for PHP, use its full path, e.g. <code>/usr/bin/php8.3</code>:</p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-white/70 border border-amber-200 px-3 py-2 text-xs text-slate-800"><code>{{ $cronLine }}</code></pre>
        <p class="mt-1 text-xs">Until then you can start any task here with <b>Run now</b>.</p>
    @endif
</div>

<div class="space-y-3">
    @foreach($tasks as $t)
        @php
            $latest = $t['latest'];
            [$cls, $label] = $badge($latest?->state());
        @endphp
        <section class="bg-white border border-slate-200 rounded-xl" data-task="{{ $t['key'] }}">
            <div class="flex flex-wrap items-start gap-x-6 gap-y-3 p-4">
                <div class="min-w-0 flex-1 basis-72">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-slate-900">{{ $t['label'] }}</h2>
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $cls }}" data-state="{{ $latest?->state() ?? 'never' }}">{{ $label }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $t['description'] }}</p>
                    <p class="mt-1.5"><code class="text-xs text-slate-600 bg-slate-100 rounded px-1.5 py-0.5 break-all">{{ $t['command_line'] }}</code></p>
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-1 text-sm">
                    <div>
                        <dt class="text-xs text-slate-400">Schedule</dt>
                        <dd class="text-slate-700">{{ $t['every'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Last run</dt>
                        <dd class="text-slate-700 tabular-nums" title="{{ $latest?->started_at?->format('d.m.Y H:i:s') }}">
                            {{ $latest ? $latest->started_at->diffForHumans() : '—' }}
                            @if($latest?->duration())<span class="text-xs text-slate-400">· {{ $latest->duration() }}</span>@endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">Next run</dt>
                        <dd class="text-slate-700 tabular-nums">{{ $t['next'] ? $t['next']->format('d.m H:i') : '—' }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('admin.scheduled-tasks.run', $t['key']) }}"
                      @if($t['cron'] === null) onsubmit="return confirm('{{ $t['label'] }}?')" @endif>
                    @csrf
                    <button type="submit" @disabled($latest?->state() === 'running' && $latest->trigger === 'manual')
                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:border-slate-500 disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z"/></svg>
                        Run now
                    </button>
                </form>
            </div>

            @if($t['recent']->isNotEmpty())
                <details class="border-t border-slate-100 group">
                    <summary class="cursor-pointer select-none px-4 py-2 text-xs font-medium text-slate-500 hover:text-slate-800">
                        Output and last {{ $t['recent']->count() }} runs
                        @if($t['last_success'])<span class="font-normal text-slate-400">· last success {{ $when(\Illuminate\Support\Carbon::parse($t['last_success'])) }}</span>@endif
                    </summary>
                    <div class="px-4 pb-4 space-y-3">
                        <pre class="max-h-64 overflow-auto rounded-lg bg-slate-900 text-slate-100 text-xs p-3 whitespace-pre-wrap break-words">{{ $latest->output ?? ($latest->state() === 'running' ? 'Running…' : '(no output)') }}</pre>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead class="text-left text-slate-400">
                                    <tr><th class="py-1 pr-4 font-medium">Started</th><th class="py-1 pr-4 font-medium">By</th><th class="py-1 pr-4 font-medium">Result</th><th class="py-1 pr-4 font-medium">Time</th><th class="py-1 font-medium">Output</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($t['recent'] as $run)
                                        @php [$rc, $rl] = $badge($run->state()); @endphp
                                        <tr>
                                            <td class="py-1.5 pr-4 tabular-nums whitespace-nowrap text-slate-600">{{ $run->started_at->format('d.m H:i:s') }}</td>
                                            <td class="py-1.5 pr-4 whitespace-nowrap text-slate-600">{{ $run->trigger === 'manual' ? ($run->user?->name ?? 'Admin') : 'Scheduler' }}</td>
                                            <td class="py-1.5 pr-4 whitespace-nowrap"><span class="px-1.5 py-0.5 rounded-full {{ $rc }}">{{ $rl }}</span>@if($run->exit_code)<span class="text-slate-400"> · code {{ $run->exit_code }}</span>@endif</td>
                                            <td class="py-1.5 pr-4 tabular-nums whitespace-nowrap text-slate-500">{{ $run->duration() ?? '—' }}</td>
                                            <td class="py-1.5 text-slate-500 max-w-md truncate" title="{{ $run->output }}">{{ \Illuminate\Support\Str::limit((string) $run->output, 120) ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            @endif
        </section>
    @endforeach
</div>

<p class="mt-4 text-xs text-slate-400">Each run is kept for {{ \App\Models\ScheduledTaskRun::KEEP_DAYS }} days. A failed scheduled run is posted to the Telegram alerts chat (at most once every {{ \App\Services\Scheduling\ScheduledTasks::ALERT_HOURS }} hours per task). Times are in {{ config('app.timezone') }}.</p>
@endsection
