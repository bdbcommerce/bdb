@extends('admin.layout')
@section('title', $employee['name'].' — AI employee')
@section('heading', 'AI employee — '.$employee['name'])

@php use App\Support\AiTeamMock; $e = $employee; @endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.ai-team._mock')

    <a href="{{ route('admin.ai-team.show') }}" class="text-sm text-slate-600 underline">All AI employees</a>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            {{-- Card --}}
            <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-4" aria-labelledby="card-h">
                <h2 id="card-h" class="text-sm font-semibold text-slate-900">Employee card</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block text-sm"><span class="text-slate-600">Name the customer sees</span>
                        <input type="text" value="{{ $e['name'] }}" disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"></label>
                    <label class="block text-sm"><span class="text-slate-600">Role</span>
                        <input type="text" value="{{ $e['role'] }}" disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"></label>
                    <label class="block text-sm sm:col-span-2"><span class="text-slate-600">Who they talk to</span>
                        <input type="text" value="{{ $e['audience'] }}" disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"></label>
                </div>
                <p class="text-sm text-slate-600">Channels: {{ implode(', ', $e['channels']) }} · Status: <span class="font-medium text-slate-900">{{ $e['status'] === 'live' ? 'Live — answers customers' : 'Draft — test chat only' }}</span></p>
            </section>

            {{-- Temperament --}}
            <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-5" aria-labelledby="temper-h">
                <div>
                    <h2 id="temper-h" class="text-sm font-semibold text-slate-900">Temperament — how {{ $e['name'] }} talks</h2>
                    <p class="text-xs text-slate-500 mt-1">Turned into the "manner" part of the prompt. The examples teach the tone better than adjectives.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (AiTeamMock::SCALES as $key => [$low, $high])
                        <div>
                            <div class="flex justify-between text-xs text-slate-500"><span>{{ $low }}</span><span>{{ $high }}</span></div>
                            <input type="range" min="1" max="5" value="{{ $e['scales'][$key] }}" disabled class="w-full accent-slate-900" aria-label="{{ $low }} to {{ $high }}">
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($e['traits'] as $t)
                        <span class="text-xs rounded-md bg-slate-100 text-slate-700 px-2 py-0.5">{{ $t }}</span>
                    @endforeach
                </div>
                <label class="block text-sm"><span class="text-slate-600">Manner, in your words</span>
                    <textarea rows="3" disabled class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm">{{ $e['manner'] }}</textarea></label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-xs font-medium text-slate-900">Say it like this</p>
                        <p class="mt-1 text-sm text-slate-700">{{ $e['say'] }}</p>
                    </div>
                    <div class="rounded-lg border border-dashed border-slate-300 p-3">
                        <p class="text-xs font-medium text-slate-900">Never like this</p>
                        <p class="mt-1 text-sm text-slate-500 line-through decoration-slate-400">{{ $e['avoid'] }}</p>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-900">Hands over to people / colleagues</p>
                    <p class="mt-1 text-sm text-slate-700">{{ $e['handover'] }}</p>
                </div>
            </section>

            {{-- Prompt --}}
            <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-3" aria-labelledby="prompt-h">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="prompt-h" class="text-sm font-semibold text-slate-900">Role prompt — draft v{{ $versions[0]['v'] }}</h2>
                    <div class="flex gap-2">
                        <button type="button" disabled class="text-sm rounded-lg border border-slate-300 px-3 py-1.5 opacity-50 cursor-not-allowed">Try in test chat</button>
                        <button type="button" disabled class="text-sm rounded-lg border border-slate-300 px-3 py-1.5 opacity-50 cursor-not-allowed">Run test cases</button>
                        <button type="button" disabled class="text-sm rounded-lg bg-slate-900 text-white px-3 py-1.5 opacity-50 cursor-not-allowed">Publish</button>
                    </div>
                </div>
                <textarea rows="14" disabled class="w-full rounded-lg border-slate-300 bg-slate-50 font-mono text-xs leading-5">{{ $e['prompt'] }}</textarea>
                <p class="text-xs text-slate-500">Publishing is blocked when the draft scores lower on the test cases than the published version.</p>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="bg-white border border-slate-200 rounded-xl p-5" aria-labelledby="ver-h">
                <h2 id="ver-h" class="text-sm font-semibold text-slate-900 mb-3">Prompt versions</h2>
                <ol class="space-y-3">
                    @foreach ($versions as $v)
                        <li class="text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-medium text-slate-900">v{{ $v['v'] }}</span>
                                <span class="text-xs rounded-full px-2 py-0.5 border {{ $v['status'] === 'published' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-600' }}">{{ ucfirst($v['status']) }}</span>
                            </div>
                            <p class="text-slate-600">{{ $v['note'] }}</p>
                            <p class="text-xs text-slate-500">{{ $v['at'] }} · tests {{ $v['eval'] !== null ? $v['eval'].'%' : 'not run' }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
            <section class="bg-white border border-slate-200 rounded-xl p-5" aria-labelledby="res-h">
                <h2 id="res-h" class="text-sm font-semibold text-slate-900 mb-3">Results (last 30 days)</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div><dt class="text-xs text-slate-500">Dialogues</dt><dd class="font-semibold">{{ $e['metrics']['dialogues'] }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Requests to managers</dt><dd class="font-semibold">{{ $e['metrics']['leads'] }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Handed to a person</dt><dd class="font-semibold">{{ $e['metrics']['handovers'] }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Audit score</dt><dd class="font-semibold">{{ $e['metrics']['audit'] ? $e['metrics']['audit'].' / 5' : '—' }}</dd></div>
                </dl>
            </section>
        </aside>
    </div>
</div>
@endsection
