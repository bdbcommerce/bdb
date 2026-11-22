{{-- One AI turn as its path: customer → Laravel backend → Claude API → Laravel backend → Telegram, with what was sent at each point.
     Needs $trace (AiTrace). Everything is escaped; long texts (the prompt, the raw answer) fold open. --}}
@php
    use App\Models\AiTrace;
    $steps = $trace->steps ?? [];
    $route = [];
    foreach ($steps as $s) {
        $point = AiTrace::POINTS[$s['where'] ?? ''] ?? ucfirst((string) ($s['where'] ?? ''));
        if (end($route) !== $point) {
            $route[] = $point;
        }
    }
    if ($trace->status === 'sent') {
        $route[] = 'Customer';
    }
    $long = ['system_prompt', 'user_message', 'raw_answer'];
    $pretty = function ($v) {
        $json = is_string($v) ? json_decode($v, true) : null;

        return is_array($json) ? json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v;
    };
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
        <span class="text-xs font-medium rounded-full px-2 py-0.5 border {{ $trace->status === 'sent' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-400 text-slate-700' }}">{{ AiTrace::STATUSES[$trace->status] ?? $trace->status }}</span>
        <span class="text-slate-600">{{ ucfirst($trace->kind) }}{{ $trace->source === 'sandbox' ? ' · sandbox' : '' }}</span>
        @if ($trace->employee)<span class="text-slate-600">{{ $trace->employee->name }}{{ $trace->prompt_version ? ' · prompt v'.$trace->prompt_version : '' }}</span>@endif
        @if ($trace->model)<span class="text-slate-600">{{ $trace->model }}</span>@endif
        <span class="text-slate-600">{{ number_format($trace->duration_ms / 1000, 1) }} s</span>
        @if ($trace->input_tokens || $trace->output_tokens)<span class="text-slate-600">{{ number_format($trace->input_tokens) }} in / {{ number_format($trace->output_tokens) }} out tokens</span>@endif
        @if ($trace->cost_usd !== null)<span class="text-slate-600">{{ \App\Services\Invoices\ClaudePricing::format($trace->cost_usd) }}</span>@endif
        <span class="text-xs text-slate-500">{{ $trace->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</span>
    </div>

    <ol class="flex flex-wrap items-center gap-1.5 text-xs" aria-label="Path of this turn">
        @foreach ($route as $i => $point)
            <li class="flex items-center gap-1.5">
                @if ($i)<span class="text-slate-400" aria-hidden="true">→</span>@endif
                <span class="rounded-md border border-slate-300 bg-white px-2 py-1 font-medium text-slate-700">{{ $point }}</span>
            </li>
        @endforeach
    </ol>

    <ol class="relative border-l border-slate-200 ml-2 space-y-4">
        @foreach ($steps as $s)
            <li class="pl-5 relative">
                <span class="absolute -left-[5px] top-1.5 w-2.5 h-2.5 rounded-full {{ ($s['where'] ?? '') === 'claude' ? 'bg-slate-900' : 'bg-slate-400' }}" aria-hidden="true"></span>
                <div class="flex flex-wrap items-baseline gap-x-3">
                    <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ AiTrace::POINTS[$s['where'] ?? ''] ?? ($s['where'] ?? '') }}</span>
                    <span class="text-sm font-medium text-slate-900">{{ $s['title'] ?? '' }}</span>
                    <span class="text-xs text-slate-500">+{{ number_format((int) ($s['ms'] ?? 0)) }} ms</span>
                </div>
                @if (! empty($s['data']))
                    <dl class="mt-1.5 space-y-1.5 text-sm">
                        @foreach ($s['data'] as $key => $value)
                            @php
                                $label = ucfirst(str_replace('_', ' ', (string) $key));
                                $isLong = in_array($key, $long, true) || (is_string($value) && (mb_strlen($value) > 300 || str_contains($value, "\n")));
                            @endphp
                            <div class="min-w-0">
                                <dt class="text-xs text-slate-500">{{ $label }}</dt>
                                <dd class="min-w-0 text-slate-800 break-words">
                                    @if (is_bool($value))
                                        {{ $value ? 'Yes' : 'No' }}
                                    @elseif ($value === null || $value === '' || $value === [])
                                        <span class="text-slate-400">—</span>
                                    @elseif (is_array($value) && array_is_list($value) && collect($value)->every(fn ($v) => is_scalar($v)))
                                        <ul class="list-disc pl-4">@foreach ($value as $v)<li>{{ $v }}</li>@endforeach</ul>
                                    @elseif (is_array($value))
                                        <pre class="mt-0.5 max-h-72 overflow-auto rounded-md bg-slate-50 border border-slate-200 p-2 text-xs leading-5 whitespace-pre-wrap">{{ json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @elseif ($isLong)
                                        <details>
                                            <summary class="cursor-pointer text-xs text-slate-600 underline">Show ({{ number_format(mb_strlen((string) $value)) }} characters)</summary>
                                            <pre class="mt-1 max-h-[28rem] overflow-auto rounded-md bg-slate-50 border border-slate-200 p-2 text-xs leading-5 whitespace-pre-wrap">{{ $key === 'raw_answer' ? $pretty($value) : $value }}</pre>
                                        </details>
                                    @else
                                        {{ $value }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </li>
        @endforeach
    </ol>
</div>
