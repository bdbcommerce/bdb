@extends('admin.layout')
@section('title', 'Client portraits')
@section('heading', 'AI Sales & Support Team — Client portraits')

@php use App\Support\AiTeamMock; @endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <p class="text-sm text-slate-600 max-w-3xl">
        One card per company, with every person we talk to there. Each person has a position, a department, the part they play in a decision
        and a status — when someone leaves, the card keeps the history and follows them to their new company. The AI employees read the portrait
        before answering (who decides, what is installed, what runs out soon); they may suggest changes, a person confirms them.
    </p>

    <div class="flex flex-wrap gap-2 text-xs text-slate-600" aria-label="Person statuses">
        @foreach (AiTeamMock::PERSON_STATUSES as $label)
            <span class="rounded-md border border-slate-300 px-2 py-0.5">{{ $label }}</span>
        @endforeach
        <span class="mx-1 text-slate-300" aria-hidden="true">|</span>
        @foreach (AiTeamMock::DECISION_PARTS as $part)
            <span class="rounded-md bg-slate-100 px-2 py-0.5">{{ $part }}</span>
        @endforeach
    </div>

    @foreach (AiTeamMock::clients() as $c)
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden" aria-label="{{ $c['company'] }}">
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start gap-4">
                <span class="w-11 h-11 rounded-lg bg-slate-200 text-slate-700 font-semibold flex items-center justify-center shrink-0" aria-hidden="true">{{ mb_substr($c['company'], 0, 1) }}</span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-semibold text-slate-900">{{ $c['company'] }} <span class="ml-2 text-xs font-medium rounded-full border border-slate-300 px-2 py-0.5 text-slate-600">{{ $c['status'] }}</span></h2>
                    <p class="text-sm text-slate-600">{{ $c['industry'] }} · {{ $c['city'] }} · {{ $c['size'] }} · INN {{ $c['inn'] }}</p>
                    <p class="text-xs text-slate-500">Client since {{ $c['since'] }} · Looks after: {{ $c['owner'] }}</p>
                </div>
            </div>
            <div class="grid gap-0 lg:grid-cols-3">
                <div class="lg:col-span-2 overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">People at {{ $c['company'] }}</caption>
                        <thead class="text-xs text-slate-500 text-left">
                            <tr><th class="px-5 py-2 font-medium">Person</th><th class="px-3 py-2 font-medium">Department</th><th class="px-3 py-2 font-medium">In decisions</th><th class="px-3 py-2 font-medium">How to talk</th><th class="px-3 py-2 font-medium">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($c['people'] as $p)
                                <tr class="{{ $p['status'] === 'active' ? '' : 'text-slate-500' }}">
                                    <td class="px-5 py-2.5"><span class="font-medium {{ $p['status'] === 'active' ? 'text-slate-900' : '' }}">{{ $p['name'] }}</span><br><span class="text-xs text-slate-500">{{ $p['position'] }} · {{ $p['channel'] }}</span></td>
                                    <td class="px-3 py-2.5">{{ $p['department'] }}</td>
                                    <td class="px-3 py-2.5">{{ $p['part'] }}</td>
                                    <td class="px-3 py-2.5 text-xs">{{ $p['style'] }}</td>
                                    <td class="px-3 py-2.5 text-xs">
                                        <span class="rounded-md border px-2 py-0.5 {{ $p['status'] === 'active' ? 'border-slate-900 text-slate-900' : 'border-dashed border-slate-400' }}">{{ AiTeamMock::PERSON_STATUSES[$p['status']] }}</span>
                                        @isset($p['moved'])<p class="mt-1 text-slate-500">{{ $p['moved'] }}</p>@endisset
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-5 py-4 border-t lg:border-t-0 lg:border-l border-slate-100 space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-900">Installed equipment</p>
                        @forelse ($c['base'] as $item)
                            <p class="text-slate-700">{{ $item }}</p>
                        @empty
                            <p class="text-slate-500">Nothing bought yet</p>
                        @endforelse
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-900">Consumables</p>
                        <p class="text-slate-700">{{ $c['consumables'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-900">Notes for the AI team</p>
                        <p class="text-slate-700">{{ $c['notes'] }}</p>
                    </div>
                </div>
            </div>
        </section>
    @endforeach
</div>
@endsection
