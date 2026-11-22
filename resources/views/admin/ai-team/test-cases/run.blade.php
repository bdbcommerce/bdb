@extends('admin.layout')
@section('title', 'Test run #'.$run->id)
@section('heading', 'Test run — '.($run->testCase?->name ?? '#'.$run->id))

@section('content')
<div class="w-full space-y-6">
    <a href="{{ route('admin.ai-test-cases.index') }}" class="text-sm text-slate-600 underline">All test cases</a>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
        <span class="font-semibold text-slate-900">{{ $run->employee?->name }} · prompt v{{ $run->prompt_version }}</span>
        @if ($run->status === 'done')
            <span class="text-xs font-medium rounded-full px-2 py-0.5 border {{ $run->passed() ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-400 text-slate-700' }}">{{ $run->score }}% · {{ $run->passed() ? 'pass' : 'fail' }} (pass from {{ \App\Models\AiTestRun::PASS_SCORE }}%)</span>
        @else
            <span class="text-slate-600">{{ ['queued' => 'Queued', 'running' => 'Running…', 'failed' => 'Could not run'][$run->status] ?? $run->status }}</span>
        @endif
        @if ($run->cost_usd !== null)<span class="text-slate-600">{{ \App\Services\Invoices\ClaudePricing::format($run->cost_usd) }}</span>@endif
        <span class="text-xs text-slate-500">{{ $run->started_by }} · {{ ($run->finished_at ?? $run->created_at)->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
    </div>
    @if ($run->error)<p role="alert" class="rounded-lg bg-slate-50 border border-slate-300 px-3 py-2 text-sm text-slate-800">{{ $run->error }}</p>@endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-3" aria-labelledby="check-h">
            <h2 id="check-h" class="text-sm font-semibold text-slate-900">Checklist</h2>
            <ul class="space-y-3">
                @foreach ($run->results ?? [] as $r)
                    <li class="text-sm">
                        <p class="font-medium {{ $r['pass'] ? 'text-slate-900' : 'text-slate-500 line-through decoration-slate-400' }}">{{ $r['pass'] ? '✓' : '✗' }} {{ $r['criterion'] }}</p>
                        <p class="text-xs text-slate-600 mt-0.5">{{ $r['reason'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
        <section class="bg-[#efeae2] border border-slate-200 rounded-xl p-4" aria-label="Conversation of this run">
            @php
                $messages = collect($run->transcript ?? [])->map(fn ($m) => ! empty($m['silent'])
                    ? ['role' => 'assistant', 'content' => '[stayed silent'.(filled($m['unknown_question'] ?? '') ? ' — passed to the team: '.$m['unknown_question'] : '').']']
                    : $m)->all();
            @endphp
            @include('admin.chat-conversations._messages', ['messages' => $messages])
        </section>
    </div>
</div>
@endsection
