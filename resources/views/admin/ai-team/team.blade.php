@extends('admin.layout')
@section('title', 'AI team')
@section('heading', 'AI Sales & Support Team — Employees')

@php use App\Support\AiTeamMock; @endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-sm text-slate-600 max-w-3xl">
            Each AI employee has a name, a role and a temperament — how they talk. A customer's chat goes to the employee whose role fits
            (a new customer to sales, a broken printer to support…). Every answer is built from three layers:
            <span class="font-medium text-slate-800">platform rules</span> (the same for everyone) →
            <span class="font-medium text-slate-800">the employee's published prompt</span> →
            <span class="font-medium text-slate-800">context</span> (date, client portrait, conversation).
        </p>
        <button type="button" disabled class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 opacity-50 cursor-not-allowed">Add AI employee</button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($employees as $e)
            <a href="{{ route('admin.ai-team.employee', $e['slug']) }}" class="group bg-white border border-slate-200 rounded-xl p-5 flex flex-col gap-3 hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                <div class="flex items-center gap-3">
                    <span class="w-11 h-11 rounded-full bg-slate-200 text-slate-700 font-semibold flex items-center justify-center shrink-0" aria-hidden="true">{{ mb_substr($e['name'], 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-900">{{ $e['name'] }}</p>
                        <p class="text-sm text-slate-600">{{ $e['role'] }}</p>
                    </div>
                    <span class="text-xs font-medium rounded-full px-2 py-0.5 border {{ $e['status'] === 'live' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-600' }}">
                        {{ $e['status'] === 'live' ? 'Live' : 'Draft' }}
                    </span>
                </div>
                <p class="text-xs text-slate-500">{{ $e['audience'] }} · {{ implode(', ', $e['channels']) }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($e['traits'] as $t)
                        <span class="text-xs rounded-md bg-slate-100 text-slate-700 px-2 py-0.5">{{ $t }}</span>
                    @endforeach
                </div>
                <p class="text-sm text-slate-700 border-l-2 border-slate-300 pl-3 italic">“{{ $e['say'] }}”</p>
                <dl class="mt-auto grid grid-cols-3 gap-2 pt-3 border-t border-slate-100 text-center">
                    <div><dt class="text-[11px] text-slate-500">Dialogues</dt><dd class="text-sm font-semibold text-slate-900">{{ $e['metrics']['dialogues'] }}</dd></div>
                    <div><dt class="text-[11px] text-slate-500">Audit</dt><dd class="text-sm font-semibold text-slate-900">{{ $e['metrics']['audit'] ? $e['metrics']['audit'].' / 5' : '—' }}</dd></div>
                    <div><dt class="text-[11px] text-slate-500">Tests</dt><dd class="text-sm font-semibold text-slate-900">{{ $e['metrics']['eval'] !== null ? $e['metrics']['eval'].'%' : '—' }}</dd></div>
                </dl>
            </a>
        @endforeach
    </div>

    <details class="bg-white border border-slate-200 rounded-xl">
        <summary class="px-5 py-4 cursor-pointer text-sm font-medium text-slate-900">Platform rules — the first layer, shared by every AI employee</summary>
        <pre class="px-5 pb-5 text-sm text-slate-700 whitespace-pre-wrap font-sans">{{ AiTeamMock::PLATFORM_RULES }}</pre>
    </details>
</div>
@endsection
