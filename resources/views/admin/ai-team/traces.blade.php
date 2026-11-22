@extends('admin.layout')
@section('title', 'Traces')
@section('heading', 'AI Sales & Support Team — Traces')

@php use App\Models\AiTrace; @endphp

@section('content')
<div class="w-full space-y-6">
    <form method="POST" action="{{ route('admin.ai-team.dev-mode') }}" class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
        @csrf @method('PUT')
        <input type="hidden" name="dev_mode" value="0">
        <label class="flex items-start gap-3">
            <input type="checkbox" name="dev_mode" value="1" @checked($devMode) class="mt-1 rounded border-slate-300" onchange="this.form.requestSubmit()">
            <span>
                <span class="text-sm font-semibold text-slate-900">Dev mode — record the trace of every real customer message</span>
                <span class="block text-sm text-slate-600 mt-0.5">Each turn of the sales assistant is saved with its full path: what the customer wrote, what Laravel decided, the full prompt sent to the Claude API, Claude's raw answer, what Laravel did with it and what went back to Telegram. Kept {{ AiTrace::KEEP_DAYS }} days; admins only. The sandbox is always traced.</span>
            </span>
        </label>
        <noscript><button class="text-sm rounded-lg border border-slate-300 px-3 py-1.5">Save</button></noscript>
    </form>

    <nav class="flex flex-wrap gap-2" aria-label="Trace source">
        @foreach (['live' => 'Real customers', 'sandbox' => 'Sandbox'] as $key => $label)
            <a href="{{ route('admin.ai-team.traces', ['source' => $key]) }}" @if($source === $key) aria-current="page" @endif
               class="text-sm rounded-lg px-3 py-1.5 border {{ $source === $key ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-700 hover:border-slate-500' }}">{{ $label }}</a>
        @endforeach
    </nav>

    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto">
        @if ($traces->isEmpty())
            <p class="px-6 py-10 text-sm text-slate-500 text-center">{{ $source === 'live' ? ($devMode ? 'No customer turns since dev mode was switched on.' : 'Switch dev mode on to record customer turns.') : 'No sandbox turns yet.' }}</p>
        @else
            <table class="w-full text-sm">
                <caption class="sr-only">Traces</caption>
                <thead class="text-xs text-slate-500 text-left border-b border-slate-100">
                    <tr><th class="px-5 py-2.5 font-medium">When</th><th class="px-3 py-2.5 font-medium">Customer wrote</th><th class="px-3 py-2.5 font-medium">AI employee</th><th class="px-3 py-2.5 font-medium">Result</th><th class="px-3 py-2.5 font-medium text-right">Time</th><th class="px-3 py-2.5 font-medium text-right">Tokens</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($traces as $t)
                        <tr class="hover:bg-slate-50">
                            <td class="px-5 py-2.5 whitespace-nowrap"><a href="{{ route('admin.ai-team.trace', $t) }}" class="underline text-slate-900">{{ $t->created_at->timezone(config('app.timezone'))->format('d.m H:i:s') }}</a></td>
                            <td class="px-3 py-2.5 min-w-[12rem]">
                                <span class="text-slate-900">{{ \Illuminate\Support\Str::limit($t->customer_text ?? ($t->kind !== 'reply' ? ucfirst($t->kind) : '—'), 90) }}</span>
                                @if ($t->conversation)<br><span class="text-xs text-slate-500">{{ $t->conversation->chatName() }}</span>@endif
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">{{ $t->employee?->name ?? '—' }}{{ $t->prompt_version ? ' · v'.$t->prompt_version : '' }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap">{{ AiTrace::STATUSES[$t->status] ?? $t->status }}{{ $t->kind !== 'reply' ? ' · '.$t->kind : '' }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ number_format($t->duration_ms / 1000, 1) }} s</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ number_format($t->input_tokens + $t->output_tokens) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div>{{ $traces->links() }}</div>
</div>
@endsection
