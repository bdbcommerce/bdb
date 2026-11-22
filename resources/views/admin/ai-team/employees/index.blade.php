@extends('admin.layout')
@section('title', 'AI employees')
@section('heading', 'AI Sales & Support Team — Employees')

@section('content')
<div class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-sm text-slate-600 max-w-3xl">
            Each AI employee has a name, a role and a temperament — how they talk. What the AI gets is built in layers:
            <span class="font-medium text-slate-800">platform rules</span> (the same for everyone, below) →
            <span class="font-medium text-slate-800">the employee's card and published prompt</span> →
            for the sales consultant, your <a href="{{ route('admin.sales-assistant.prompt') }}" class="underline">sales playbook</a>, working hours and knowledge.
            A chat starts with the sales consultant, who hands it to <span class="font-medium text-slate-800">technical support</span> or the <span class="font-medium text-slate-800">service desk</span> when the question is theirs.
            <span class="font-medium text-slate-800">Live</span> employees answer; <span class="font-medium text-slate-800">Shadow</span> employees only draft replies for a manager to send.
        </p>
        <div class="flex flex-wrap gap-2">
            @foreach ($templates as $key => $t)
                <a href="{{ route('admin.ai-employees.create', ['template' => $key]) }}" class="text-sm rounded-lg border border-slate-300 px-3 py-2 hover:border-slate-500">Add {{ $t['name'] }} ({{ $t['role'] }})</a>
            @endforeach
            <a href="{{ route('admin.ai-employees.create') }}" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Add AI employee</a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($employees as $e)
            @php
                $published = $e->prompts->firstWhere('status', 'published');
                $draft = $e->prompts->firstWhere('status', 'draft');
            @endphp
            <a href="{{ route('admin.ai-employees.edit', $e) }}" class="bg-white border border-slate-200 rounded-xl p-5 flex flex-col gap-3 hover:border-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                <div class="flex items-center gap-3">
                    <span class="w-11 h-11 rounded-full bg-slate-200 text-slate-700 font-semibold flex items-center justify-center shrink-0" aria-hidden="true">{{ mb_substr($e->name, 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-900">{{ $e->name }}</p>
                        <p class="text-sm text-slate-600">{{ $e->role }}</p>
                    </div>
                    <span class="text-xs font-medium rounded-full px-2 py-0.5 border {{ $e->isLive() ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-600' }}">{{ \App\Models\AiEmployee::STATUSES[$e->status] ?? $e->status }}</span>
                </div>
                <p class="text-xs text-slate-500">{{ $e->audience }}{{ $e->channels ? ' · '.collect($e->channels)->map(fn ($c) => \App\Models\AiEmployee::CHANNELS[$c] ?? $c)->implode(', ') : '' }}</p>
                @if ($e->traits)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($e->traits as $t)
                            <span class="text-xs rounded-md bg-slate-100 text-slate-700 px-2 py-0.5">{{ $t }}</span>
                        @endforeach
                    </div>
                @endif
                @if ($e->say)
                    <p class="text-sm text-slate-700 border-l-2 border-slate-300 pl-3 italic">“{{ $e->say }}”</p>
                @endif
                <p class="mt-auto pt-3 border-t border-slate-100 text-xs text-slate-500">
                    Prompt: {{ $published ? 'v'.$published->version.' published' : 'not published' }}{{ $draft ? ' · draft v'.$draft->version : '' }}
                </p>
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('admin.ai-team.rules') }}" class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
        @csrf @method('PUT')
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-900">Platform rules — the first layer, shared by every AI employee</h2>
            <span class="text-xs text-slate-500">{{ $customRules ? 'Edited' : 'Default' }} · {name} and {role} are filled in per employee</span>
        </div>
        <label for="platform_rules" class="sr-only">Platform rules</label>
        <textarea id="platform_rules" name="platform_rules" rows="12" maxlength="10000" class="w-full rounded-lg border-slate-300 font-mono text-xs leading-5">{{ old('platform_rules', $rules) }}</textarea>
        @error('platform_rules')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <div class="flex flex-wrap gap-2">
            <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Save rules</button>
            @if ($customRules)
                <button name="platform_rules" value="" class="text-sm rounded-lg border border-slate-300 px-4 py-2 hover:border-slate-500">Reset to default</button>
            @endif
        </div>
    </form>
</div>
@endsection
