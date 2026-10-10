@extends('admin.layout')
@php $isMain = $instruction->isMain(); @endphp
@section('title', $isMain ? 'Main prompt' : ($instruction->exists ? 'Edit AI document' : 'New AI document'))
@section('heading', $isMain ? 'Main prompt' : ($instruction->exists ? 'Edit AI document' : 'New AI document'))

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
@endphp

@section('content')
<form method="POST"
      action="{{ $instruction->exists ? route('admin.ai-instructions.update', $instruction) : route('admin.ai-instructions.store') }}"
      class="grid gap-6 lg:grid-cols-3">
    @csrf
    @if($instruction->exists) @method('PUT') @endif

    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-6 space-y-4 min-w-0">
        @if($isMain)
            <p class="text-xs text-slate-500">
                The AI's core instructions, sent first with every question on the website and in Telegram.
                Keep the <b>STRICT RULE</b> (answer only from website data): without it the AI may invent prices or facts.
                Reply language, length and the technical reply fields are added automatically after this text.
            </p>
        @else
        <div>
            <label for="title" class="block text-sm font-medium text-slate-700 mb-1">Title</label>
            <input id="title" name="title" type="text" required maxlength="150" value="{{ old('title', $instruction->title) }}"
                   placeholder="e.g. Tone and greeting, Office hours, What never to promise" class="{{ $input }}">
        </div>
        @endif
        <div>
            <label for="content" class="block text-sm font-medium text-slate-700 mb-1">Content (Markdown)</label>
            <textarea id="content" name="content" rows="18" maxlength="{{ \App\Models\AiInstruction::MAX_LENGTH }}"
                      class="{{ $input }} font-mono">{{ old('content', $instruction->content) }}</textarea>
            <p class="text-xs text-slate-400 mt-1">
                Write in any language; short, clear bullet points work best. Example rules:
                “- Always answer in the customer's language”, “- Never promise delivery dates; offer a call instead”.
                Up to {{ number_format(\App\Models\AiInstruction::MAX_LENGTH) }} characters.
            </p>
        </div>
    </div>

    <aside class="bg-white border border-slate-200 rounded-xl p-6 space-y-4 h-fit">
        @unless($isMain)
        <fieldset>
            <legend class="block text-sm font-medium text-slate-700 mb-2">Type</legend>
            @foreach($kinds as $key => $label)
                <label class="flex items-start gap-2 text-sm text-slate-700 mb-1.5">
                    <input type="radio" name="kind" value="{{ $key }}" class="mt-0.5" @checked(old('kind', $instruction->kind) === $key)>
                    {{ $label }}
                </label>
            @endforeach
        </fieldset>
        <div>
            <label for="channel" class="block text-sm font-medium text-slate-700 mb-1">Used in</label>
            <select id="channel" name="channel" class="{{ $input }}">
                @foreach($channels as $key => $label)
                    <option value="{{ $key }}" @selected(old('channel', $instruction->channel) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="sort_order" class="block text-sm font-medium text-slate-700 mb-1">Order</label>
            <input id="sort_order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $instruction->sort_order ?? 0) }}" class="{{ $input }}">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" @checked(session()->hasOldInput() ? old('is_active') : ($instruction->is_active ?? true))>
            Active (sent to the AI)
        </label>
        @endunless
        <div class="flex flex-col gap-2 pt-2 border-t border-slate-100">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save</button>
            <a href="{{ route('admin.ai-instructions.index') }}" class="text-center text-sm text-slate-500 hover:text-slate-900">Cancel</a>
        </div>
    </aside>
</form>

@if($isMain)
    <form method="POST" action="{{ route('admin.ai-instructions.reset') }}" class="mt-4"
          onsubmit="return confirm('Replace the main prompt with the default text? Your edits will be lost.')">
        @csrf
        <button type="submit" class="text-xs text-slate-500 underline hover:text-slate-900">Reset to default</button>
    </form>
@endif
@endsection

@push('scripts')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
    <script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
    <script>
        // Markdown editor with toolbar and side-by-side preview; the textarea stays the form field.
        if (window.EasyMDE) {
            new EasyMDE({
                element: document.getElementById('content'),
                spellChecker: false,
                status: ['lines', 'words'],
                minHeight: '360px',
                forceSync: true,
                toolbar: ['heading-2', 'heading-3', 'bold', 'italic', '|', 'unordered-list', 'ordered-list', 'quote', 'link', 'table', '|', 'preview', 'side-by-side', 'fullscreen', '|', 'guide'],
            });
        }
    </script>
@endpush
