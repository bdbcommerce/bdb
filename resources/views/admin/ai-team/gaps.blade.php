@extends('admin.layout')
@section('title', 'Knowledge gaps')
@section('heading', 'AI Sales & Support Team — Knowledge gaps')

@php use App\Support\AiTeamMock; @endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <p class="text-sm text-slate-600 max-w-3xl">Questions the AI team could not answer from the knowledge, grouped by topic and sorted by how often customers ask. Answer a topic once and every AI employee knows it; the customers who asked can be sent the answer.</p>

    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">Knowledge gaps</caption>
            <thead class="text-xs text-slate-500 text-left border-b border-slate-100">
                <tr><th class="px-5 py-2.5 font-medium">Topic</th><th class="px-3 py-2.5 font-medium">Goes to</th><th class="px-3 py-2.5 font-medium text-right">Asked</th><th class="px-3 py-2.5 font-medium">Last</th><th class="px-3 py-2.5"><span class="sr-only">Action</span></th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach (AiTeamMock::gaps() as $g)
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-900">{{ $g['topic'] }}</td>
                        <td class="px-3 py-3 text-slate-600">{{ $g['kind'] }}</td>
                        <td class="px-3 py-3 text-right font-semibold">{{ $g['asked'] }}</td>
                        <td class="px-3 py-3 text-slate-600">{{ $g['last'] }}</td>
                        <td class="px-3 py-3 text-right"><button type="button" disabled class="text-xs rounded-lg border border-slate-300 px-3 py-1 opacity-50 cursor-not-allowed">Answer</button></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
