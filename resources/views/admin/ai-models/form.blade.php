@extends('admin.layout')
@section('title', $model->exists ? 'Edit AI model' : 'Add AI model')
@section('heading', $model->exists ? 'Edit AI model' : 'Add AI model')

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $field = fn (string $name) => $errors->has($name) ? ' border-red-400' : '';
@endphp

@section('content')
<form method="POST" action="{{ $model->exists ? route('admin.ai-models.update', $model) : route('admin.ai-models.store') }}"
      class="grid gap-6 lg:grid-cols-3" x-data="{ provider: @js(old('provider', $model->provider)) }">
    @csrf
    @if($model->exists) @method('PUT') @endif

    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-6 space-y-4 min-w-0">
        @if($preset)
            <div class="text-sm text-slate-700 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 space-y-2">
                <p class="text-xs text-slate-600">{{ $preset['note'] }}</p>
                <p class="font-medium text-slate-900">How to get the key</p>
                <ol class="list-decimal list-inside space-y-1 text-xs text-slate-600">
                    @foreach($preset['steps'] ?? [] as $step)
                        <li>{!! preg_replace('~(https://[^\s)"]+)~', '<a href="$1" target="_blank" rel="noopener noreferrer" class="underline">$1</a>', e($step)) !!}</li>
                    @endforeach
                </ol>
            </div>
        @endif

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Name</label>
            <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $model->name) }}" class="{{ $input.$field('name') }}">
        </div>

        <div>
            <label for="provider" class="block text-sm font-medium text-slate-700 mb-1">Provider</label>
            <select id="provider" name="provider" x-model="provider" class="{{ $input }}">
                @foreach($providers as $key => $label)
                    <option value="{{ $key }}" @selected(old('provider', $model->provider) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="model" class="block text-sm font-medium text-slate-700 mb-1">Model name</label>
            <input id="model" name="model" type="text" required maxlength="150" value="{{ old('model', $model->model) }}" spellcheck="false"
                   placeholder="e.g. gemini-flash-latest or llama-3.3-70b-versatile" class="{{ $input.$field('model') }} font-mono">
            <p class="text-xs text-slate-400 mt-1">Exactly as the provider names it.</p>
        </div>

        <div x-show="provider === 'openai'" x-cloak>
            <label for="base_url" class="block text-sm font-medium text-slate-700 mb-1">API address (base URL)</label>
            <input id="base_url" name="base_url" type="url" maxlength="255" value="{{ old('base_url', $model->base_url) }}" spellcheck="false"
                   placeholder="https://api.groq.com/openai/v1" class="{{ $input.$field('base_url') }} font-mono">
            <p class="text-xs text-slate-400 mt-1">The OpenAI-compatible endpoint, without /chat/completions.</p>
        </div>

        <div>
            <label for="api_key" class="block text-sm font-medium text-slate-700 mb-1">API key</label>
            <input id="api_key" name="api_key" type="password" maxlength="500" autocomplete="off" spellcheck="false"
                   placeholder="{{ filled($model->api_key) ? 'Saved — leave blank to keep it' : 'Paste the key' }}" class="{{ $input.$field('api_key') }} font-mono">
            <p class="text-xs text-slate-400 mt-1">
                Stored encrypted, never shown again.
                <span x-show="provider === 'gemini'">@if($envKey) Leave empty to use GEMINI_API_KEY from .env. @endif</span>
            </p>
            @if(filled($model->api_key))
                <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="remove_key" value="1"> Remove the saved key
                </label>
            @endif
        </div>
    </div>

    <aside class="bg-white border border-slate-200 rounded-xl p-6 space-y-4 h-fit">
        <fieldset>
            <legend class="block text-sm font-medium text-slate-700 mb-2">Use this model for</legend>
            @php $chosen = old('tasks', $model->tasks ?? []); @endphp
            @foreach($tasks as $key => $label)
                <label class="flex items-start gap-2 text-sm text-slate-700 mb-1.5">
                    <input type="checkbox" name="tasks[]" value="{{ $key }}" class="mt-0.5" @checked(in_array($key, (array) $chosen, true))>
                    {{ $label }}
                </label>
            @endforeach
        </fieldset>
        <div>
            <label for="priority" class="block text-sm font-medium text-slate-700 mb-1">Priority</label>
            <input id="priority" name="priority" type="number" min="1" max="999" required value="{{ old('priority', $model->priority) }}" class="{{ $input.$field('priority') }}">
            <p class="text-xs text-slate-400 mt-1">Lower is tried first. E.g. 10 for the main model, 20 and 30 for backups.</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" @checked(session()->hasOldInput() ? old('is_active') : $model->is_active)>
            Active
        </label>
        <div class="flex flex-col gap-2 pt-2 border-t border-slate-100">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save</button>
            <a href="{{ route('admin.ai-models.index') }}" class="text-center text-sm text-slate-500 hover:text-slate-900">Cancel</a>
        </div>
    </aside>
</form>
@endsection
