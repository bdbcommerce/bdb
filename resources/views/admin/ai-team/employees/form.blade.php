@extends('admin.layout')
@php
    use App\Models\AiEmployee;
    $e = $employee;
    $editing = $e->exists;
@endphp
@section('title', $editing ? $e->name.' — AI employee' : 'New AI employee')
@section('heading', $editing ? 'AI employee — '.$e->name : 'New AI employee')

@section('content')
<div class="w-full space-y-6">
    <a href="{{ route('admin.ai-employees.index') }}" class="text-sm text-slate-600 underline">All AI employees</a>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <form method="POST" action="{{ $editing ? route('admin.ai-employees.update', $e) : route('admin.ai-employees.store') }}" class="space-y-6">
                @csrf
                @if ($editing) @method('PUT') @endif

                <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-4" aria-labelledby="card-h">
                    <h2 id="card-h" class="text-sm font-semibold text-slate-900">Employee card</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm"><span class="text-slate-600">Name the customer sees</span>
                            <input name="name" type="text" maxlength="40" required value="{{ old('name', $e->name) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                        <label class="block text-sm"><span class="text-slate-600">Role title</span>
                            <input name="role" type="text" maxlength="80" required value="{{ old('role', $e->role) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                        <label class="block text-sm"><span class="text-slate-600">Kind of work</span>
                            <select name="kind" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                @foreach (AiEmployee::KINDS as $k => $label)
                                    <option value="{{ $k }}" @selected(old('kind', $e->kind) === $k)>{{ $label }}</option>
                                @endforeach
                            </select></label>
                        <label class="block text-sm"><span class="text-slate-600">Status</span>
                            <select name="status" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                                @foreach (AiEmployee::STATUSES as $k => $label)
                                    <option value="{{ $k }}" @selected(old('status', $e->status) === $k)>{{ $label }}{{ ['live' => ' — answers customers', 'shadow' => ' — drafts replies, a manager sends them', 'draft' => ' — not used with customers'][$k] ?? '' }}</option>
                                @endforeach
                            </select></label>
                        <label class="block text-sm sm:col-span-2"><span class="text-slate-600">Who they talk to</span>
                            <input name="audience" type="text" maxlength="200" value="{{ old('audience', $e->audience) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                    </div>
                    <fieldset class="text-sm">
                        <legend class="text-slate-600">Chats</legend>
                        <div class="mt-1 flex flex-wrap gap-4">
                            @foreach (AiEmployee::CHANNELS as $k => $label)
                                <label class="inline-flex items-center gap-2"><input type="checkbox" name="channels[]" value="{{ $k }}" @checked(in_array($k, $errors->any() ? (array) old('channels', []) : ($e->channels ?? []), true)) class="rounded border-slate-300"> {{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <p class="text-xs text-slate-500">A chat starts with the sales consultant, who hands it to technical support or the service desk when the question is theirs. One employee per role can be in Shadow or Live. Shadow: every reply waits for a manager (AI team → Shadow replies). The bot and Premium-account chats themselves are switched on in Settings.</p>
                </section>

                <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-5" aria-labelledby="temper-h">
                    <div>
                        <h2 id="temper-h" class="text-sm font-semibold text-slate-900">Temperament — how {{ $e->name ?: 'they' }} talk{{ $e->name ? 's' : '' }}</h2>
                        <p class="text-xs text-slate-500 mt-1">Written into the prompt as plain instructions. The two examples teach the tone better than adjectives.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (AiEmployee::SCALES as $key => [$low, $high])
                            <label class="block">
                                <span class="flex justify-between text-xs text-slate-500"><span>{{ $low }}</span><span>{{ $high }}</span></span>
                                <input type="range" name="scales[{{ $key }}]" min="1" max="5" value="{{ old('scales.'.$key, $e->scale($key)) }}" class="w-full accent-slate-900" aria-label="{{ ucfirst($key) }}: {{ $low }} to {{ $high }}">
                            </label>
                        @endforeach
                    </div>
                    <label class="block text-sm"><span class="text-slate-600">Character (comma separated)</span>
                        <input name="traits" type="text" maxlength="300" value="{{ old('traits', implode(', ', $e->traits ?? [])) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm" placeholder="Warm, Patient, Never pushes"></label>
                    <label class="block text-sm"><span class="text-slate-600">Manner, in your words</span>
                        <textarea name="manner" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('manner', $e->manner) }}</textarea></label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm"><span class="text-slate-600">Say it like this</span>
                            <textarea name="say" rows="3" maxlength="500" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('say', $e->say) }}</textarea></label>
                        <label class="block text-sm"><span class="text-slate-600">Never like this</span>
                            <textarea name="avoid" rows="3" maxlength="500" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('avoid', $e->avoid) }}</textarea></label>
                    </div>
                    <label class="block text-sm"><span class="text-slate-600">When to hand over to a person or a colleague</span>
                        <textarea name="handover" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 text-sm">{{ old('handover', $e->handover) }}</textarea></label>
                </section>

                @unless ($editing)
                    <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-3" aria-labelledby="first-prompt-h">
                        <h2 id="first-prompt-h" class="text-sm font-semibold text-slate-900">Role prompt — first draft</h2>
                        <textarea name="prompt" rows="12" maxlength="20000" required class="w-full rounded-lg border-slate-300 font-mono text-xs leading-5" aria-labelledby="first-prompt-h">{{ old('prompt', $prompt) }}</textarea>
                    </section>
                @endunless

                <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">{{ $editing ? 'Save card' : 'Add AI employee' }}</button>
            </form>

            @if ($editing)
                <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-3" aria-labelledby="prompt-h">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="prompt-h" class="text-sm font-semibold text-slate-900">Role prompt{{ $draft ? ' — draft v'.$draft->version : '' }}</h2>
                        @if ($draft)
                            <form method="POST" action="{{ route('admin.ai-employees.publish', $e) }}" onsubmit="return confirm(@js('Publish draft v'.$draft->version.'?'.($e->isLive() ? ' Customers get it from the next message.' : '')))">
                                @csrf
                                <button class="text-sm rounded-lg bg-slate-900 text-white px-3 py-1.5 hover:bg-slate-700">Publish v{{ $draft->version }}</button>
                            </form>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('admin.ai-employees.draft', $e) }}" class="space-y-3">
                        @csrf
                        <textarea name="prompt" rows="14" maxlength="20000" required class="w-full rounded-lg border-slate-300 font-mono text-xs leading-5" aria-labelledby="prompt-h">{{ old('prompt', $prompt) }}</textarea>
                        <label class="block text-sm"><span class="text-slate-600">What changed (for the version list)</span>
                            <input name="note" type="text" maxlength="200" value="{{ old('note', $draft?->note) }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                        <button class="text-sm rounded-lg border border-slate-300 px-4 py-2 hover:border-slate-500">Save as draft</button>
                        <p class="text-xs text-slate-500">Customers keep getting the published version until you publish the draft.</p>
                    </form>
                </section>

                <details class="bg-white border border-slate-200 rounded-xl">
                    <summary class="px-5 py-4 cursor-pointer text-sm font-medium text-slate-900">What the AI gets ({{ $previewOf ?? 'no prompt yet' }}){{ $e->kind === 'sales' ? ' — then the sales playbook, working hours and knowledge' : '' }}</summary>
                    <pre class="px-5 pb-5 text-xs text-slate-700 whitespace-pre-wrap break-words font-mono leading-5">{{ $preview }}</pre>
                </details>
            @endif
        </div>

        @if ($editing)
            <aside class="space-y-6">
                <section class="bg-white border border-slate-200 rounded-xl p-5" aria-labelledby="ver-h">
                    <h2 id="ver-h" class="text-sm font-semibold text-slate-900 mb-3">Prompt versions</h2>
                    <ol class="space-y-3">
                        @foreach ($versions as $v)
                            <li class="text-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-medium text-slate-900">v{{ $v->version }}</span>
                                    <span class="text-xs rounded-full px-2 py-0.5 border {{ $v->status === 'published' ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-600' }}">{{ \App\Models\AiEmployeePrompt::STATUSES[$v->status] ?? $v->status }}</span>
                                </div>
                                @if ($v->note)<p class="text-slate-600">{{ $v->note }}</p>@endif
                                <p class="text-xs text-slate-500">{{ ($v->published_at ?? $v->updated_at)?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}{{ $v->author ? ' · '.$v->author : '' }}</p>
                                @if ($v->status === 'archived')
                                    <form method="POST" action="{{ route('admin.ai-employees.copy', [$e, $v]) }}">
                                        @csrf
                                        <button class="mt-1 text-xs underline text-slate-600 hover:text-slate-900">Copy into the draft</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>

                @php $shadow = \App\Models\AiShadowDraft::stats($e->id); @endphp
                @if ($shadow['decided'] || $e->isShadow())
                    <section class="bg-white border border-slate-200 rounded-xl p-5" aria-labelledby="shadow-h">
                        <h2 id="shadow-h" class="text-sm font-semibold text-slate-900">Shadow replies</h2>
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $shadow['rate'] !== null ? $shadow['rate'].'%' : '—' }}</p>
                        <p class="text-xs text-slate-500">sent unchanged · {{ $shadow['decided'] }} decided ({{ $shadow['edited'] }} edited, {{ $shadow['discarded'] }} discarded)</p>
                        <a href="{{ route('admin.ai-team.shadow') }}" class="mt-2 inline-block text-xs underline text-slate-600">Shadow replies</a>
                    </section>
                @endif

                @unless ($e->isLive())
                    <form method="POST" action="{{ route('admin.ai-employees.destroy', $e) }}" onsubmit="return confirm(@js('Delete '.$e->name.' and all prompt versions?'))">
                        @csrf @method('DELETE')
                        <button class="text-sm text-slate-500 underline hover:text-red-700">Delete this AI employee</button>
                    </form>
                @endunless
            </aside>
        @endif
    </div>
</div>
@endsection
