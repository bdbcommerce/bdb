@extends('admin.layout')
@section('title', 'Test cases')
@section('heading', 'AI Sales & Support Team — Test cases')

@section('content')
<div class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-sm text-slate-600 max-w-3xl">
            The exam every prompt version takes. A case is a scripted customer (one message per turn) and a checklist. A run plays the customer
            against an employee's draft or published prompt — real Claude calls, nothing reaches Telegram — and Claude Haiku grades each checklist item.
            A draft that scores lower than the published version cannot be published. Mistakes found in the sandbox or in real chats become new cases.
        </p>
        <a href="{{ route('admin.ai-test-cases.create') }}" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Add test case</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($employees as ['employee' => $e, 'published' => $p, 'draft' => $d])
            <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-2 text-sm" aria-label="{{ $e->name }}">
                <p class="font-semibold text-slate-900">{{ $e->name }} <span class="ml-1 text-xs font-normal text-slate-500">{{ $e->role }} · {{ $p['cases'] }} cases</span></p>
                @foreach (['published' => [$p, $e->publishedPrompt()], 'draft' => [$d, $e->draftPrompt()]] as $which => [$t, $version])
                    @if ($version)
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ ucfirst($which) }} v{{ $version->version }}: <span class="font-semibold">{{ $t['score'] !== null ? $t['score'].'%' : '—' }}</span>
                                <span class="text-xs text-slate-500">{{ $t['done'] }}/{{ $t['cases'] }}{{ $t['waiting'] ? ' · '.$t['waiting'].' running' : '' }}</span></span>
                            <form method="POST" action="{{ route('admin.ai-employees.tests', [$e, $which]) }}">
                                @csrf
                                <button class="text-xs rounded-lg border border-slate-300 px-2.5 py-1 hover:border-slate-500" @disabled(! $p['cases'])>Run</button>
                            </form>
                        </div>
                    @endif
                @endforeach
            </section>
        @endforeach
    </div>

    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">Test cases</caption>
            <thead class="text-xs text-slate-500 text-left border-b border-slate-100">
                <tr><th class="px-5 py-2.5 font-medium">Case</th><th class="px-3 py-2.5 font-medium">AI employee</th><th class="px-3 py-2.5 font-medium">Customer says</th><th class="px-3 py-2.5 font-medium">Checklist</th><th class="px-3 py-2.5"><span class="sr-only">Actions</span></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($cases as $case)
                    <tr class="{{ $case->is_active ? '' : 'opacity-60' }}">
                        <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $case->name }}</span>@unless($case->is_active)<span class="ml-1 text-xs text-slate-500">off</span>@endunless<br><span class="text-xs text-slate-500">{{ $case->via === 'assistant' ? 'Premium-account chat' : 'bot chat' }}</span></td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ $case->employee?->name ?? 'Every employee' }}</td>
                        <td class="px-3 py-3 min-w-[14rem]"><ol class="list-decimal pl-4 text-xs text-slate-700 space-y-0.5">@foreach ($case->turns as $t)<li>{{ $t }}</li>@endforeach</ol></td>
                        <td class="px-3 py-3 min-w-[14rem]"><ul class="list-disc pl-4 text-xs text-slate-600 space-y-0.5">@foreach ($case->criteria as $c)<li>{{ $c }}</li>@endforeach</ul></td>
                        <td class="px-3 py-3 text-right whitespace-nowrap"><a href="{{ route('admin.ai-test-cases.edit', $case) }}" class="text-xs underline text-slate-600">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-8 text-center text-slate-500">No test cases yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($recent->isNotEmpty())
        <section class="bg-white border border-slate-200 rounded-xl overflow-x-auto" aria-labelledby="runs-h">
            <h2 id="runs-h" class="px-5 py-3 text-sm font-semibold text-slate-900 border-b border-slate-100">Recent runs</h2>
            <table class="w-full text-sm">
                <caption class="sr-only">Recent test runs</caption>
                <thead class="text-xs text-slate-500 text-left"><tr><th class="px-5 py-2 font-medium">When</th><th class="px-3 py-2 font-medium">Case</th><th class="px-3 py-2 font-medium">Prompt</th><th class="px-3 py-2 font-medium text-right">Score</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($recent as $r)
                        <tr>
                            <td class="px-5 py-2.5 whitespace-nowrap"><a href="{{ route('admin.ai-test-runs.show', $r) }}" class="underline text-slate-900">{{ $r->created_at->timezone(config('app.timezone'))->format('d.m H:i') }}</a></td>
                            <td class="px-3 py-2.5">{{ $r->testCase?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap">{{ $r->employee?->name }} v{{ $r->prompt_version }}</td>
                            <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                @if ($r->status === 'done')
                                    <span class="font-semibold">{{ $r->score }}%</span> <span class="text-xs {{ $r->passed() ? 'text-slate-900' : 'text-slate-500' }}">{{ $r->passed() ? 'pass' : 'fail' }}</span>
                                @else
                                    <span class="text-xs text-slate-600">{{ ['queued' => 'Queued', 'running' => 'Running…', 'failed' => 'Could not run'][$r->status] ?? $r->status }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
