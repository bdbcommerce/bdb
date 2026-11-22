@extends('admin.layout')
@section('title', 'Test cases')
@section('heading', 'AI Sales & Support Team — Test cases')

@php use App\Support\AiTeamMock; @endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-sm text-slate-600 max-w-3xl">The exam every prompt version must pass. A case is a short scripted customer plus a checklist; a cheaper model (Claude Haiku) plays the customer and grades the answers. A draft that scores lower than the published version cannot be published. Mistakes found in the audit become new cases, so they never come back.</p>
        <button type="button" disabled class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 opacity-50 cursor-not-allowed">Run all on the drafts</button>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">Test cases</caption>
            <thead class="text-xs text-slate-500 text-left border-b border-slate-100">
                <tr><th class="px-5 py-2.5 font-medium">Case</th><th class="px-3 py-2.5 font-medium">AI employee</th><th class="px-3 py-2.5 font-medium">Checklist</th><th class="px-3 py-2.5 font-medium text-right">Last run</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach (AiTeamMock::testCases() as $case)
                    <tr>
                        <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $case['name'] }}</span><br><span class="text-xs text-slate-500">{{ $case['turns'] }} customer turns</span></td>
                        <td class="px-3 py-3">{{ $case['employee'] }}</td>
                        <td class="px-3 py-3"><ul class="text-xs text-slate-600 space-y-0.5">@foreach ($case['criteria'] as $c)<li>{{ $c }}</li>@endforeach</ul></td>
                        <td class="px-3 py-3 text-right whitespace-nowrap">
                            <span class="font-semibold">{{ $case['last'][0] }}%</span>
                            <span class="ml-1 text-xs rounded-full px-2 py-0.5 border {{ $case['last'][1] === 'pass' ? 'border-slate-900 text-slate-900' : 'border-dashed border-slate-400 text-slate-500' }}">{{ $case['last'][1] === 'pass' ? 'Pass' : 'Fail' }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
