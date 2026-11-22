@extends('admin.layout')
@section('title', 'Sales assistant prompt')
@section('heading', 'Sales assistant — Prompt')

@section('content')
<div class="w-full space-y-6">
    <form method="POST" action="{{ route('admin.sales-assistant.prompt.update') }}" class="bg-white border border-slate-200 rounded-xl p-6 space-y-3"
          x-data="{ prompt: @js(old('prompt', $settings->promptText())), original: @js($defaultPrompt) }">
        @csrf @method('PUT')
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-medium text-slate-900">How the assistant talks</h2>
            <button type="button" x-show="prompt.trim() !== original.trim()" @click="prompt = original" class="text-xs text-slate-500 hover:text-slate-900 underline">Reset to the built-in text</button>
        </div>
        <p class="text-xs text-slate-500">The rules from your answers: language, tone, start and end of a conversation, contacts, never prices or invented facts. Products, sets and facts belong in <a href="{{ route('admin.sales-knowledge.index') }}" class="underline">Knowledge</a>, not here.</p>
        <textarea name="prompt" aria-label="Sales assistant prompt" rows="24" x-model="prompt" class="w-full border border-slate-300 rounded-lg px-3 py-2 font-mono text-xs leading-relaxed focus:outline-none focus:ring-2 focus:ring-slate-400"></textarea>
        @error('prompt')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="flex justify-end">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save prompt</button>
        </div>
    </form>

    <details class="bg-white border border-slate-200 rounded-xl p-6">
        <summary class="text-sm font-medium text-slate-900 cursor-pointer">Preview: the full prompt the assistant gets (prompt + working hours + knowledge)</summary>
        <pre class="mt-4 whitespace-pre-wrap break-words text-xs text-slate-700 font-mono" data-preview>{{ $preview }}</pre>
    </details>
</div>
@endsection
