@extends('admin.layout')
@section('title', 'Dialogue audit')
@section('heading', 'AI Sales & Support Team — Dialogue audit')

@php use App\Support\AiTeamMock; $criteria = ['Found the need before advising', 'Facts only from knowledge', 'Right handover, full summary', 'Tone matches the employee card', 'Customer has a clear next step']; @endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <p class="text-sm text-slate-600 max-w-3xl">Every day Rustam (the AI head of sales) and you review a sample of real dialogues with one score sheet. Each mistake gets a cause, and the cause decides the fix: missing knowledge → a knowledge entry, a prompt rule → a new prompt draft, any mistake → a test case so it is checked from then on.</p>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="bg-white border border-slate-200 rounded-xl overflow-hidden" aria-labelledby="sample-h">
            <h2 id="sample-h" class="px-5 py-3 text-sm font-semibold text-slate-900 border-b border-slate-100">Today's sample</h2>
            <ul class="divide-y divide-slate-100">
                @foreach (AiTeamMock::audits() as $a)
                    <li class="px-5 py-3 text-sm {{ $loop->first ? 'bg-slate-50' : '' }}">
                        <div class="flex justify-between gap-2"><span class="font-medium text-slate-900">#{{ $a['id'] }} · {{ $a['customer'] }}</span><span class="font-semibold">{{ $a['score'] }}/5</span></div>
                        <p class="text-xs text-slate-500">{{ $a['employee'] }}{{ $a['cause'] ? ' · '.$a['cause'] : '' }}</p>
                        <p class="text-slate-600 mt-0.5">{{ $a['note'] }}</p>
                        @if($a['fix'])<p class="text-xs text-slate-500 mt-0.5">Fix: {{ $a['fix'] }}</p>@endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-5 space-y-4" aria-labelledby="sheet-h">
            <h2 id="sheet-h" class="text-sm font-semibold text-slate-900">Score sheet — dialogue #418 (Malika)</h2>
            <table class="w-full text-sm">
                <caption class="sr-only">Checklist scores</caption>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($criteria as $i => $c)
                        <tr>
                            <th scope="row" class="py-2 text-left font-normal text-slate-700">{{ $c }}</th>
                            <td class="py-2 text-right">
                                @foreach (range(1, 5) as $n)
                                    <span class="inline-flex w-7 h-7 items-center justify-center rounded-md border text-xs {{ $n === [2, 5, 4, 4, 4][$i] ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-200 text-slate-500' }}">{{ $n }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="grid gap-4 sm:grid-cols-2 text-sm">
                <label class="block"><span class="text-slate-600">Cause of the mistake</span>
                    <select disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"><option>Prompt rule</option><option>Missing knowledge</option><option>Tone</option><option>Wrong handover</option><option>Engine bug</option></select></label>
                <label class="block"><span class="text-slate-600">Fix</span>
                    <select disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"><option>New prompt draft + test case</option><option>Knowledge entry</option><option>Test case only</option></select></label>
            </div>
            <label class="block text-sm"><span class="text-slate-600">What should have been written</span>
                <textarea rows="3" disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm">Sizda nechta kassa bor va qanday dastur ishlatasiz? Shunga qarab mos skanerni taklif qilaman.</textarea></label>
        </section>
    </div>
</div>
@endsection
