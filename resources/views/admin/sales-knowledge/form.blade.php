@extends('admin.layout')
@section('title', 'Sales knowledge')
@section('heading', 'Sales assistant — '.($entry->exists ? 'Edit' : 'Add').' '.\App\Models\SalesKnowledge::label($entry->kind))

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    [$label, $help, $titleLabel, $bodyLabel] = \App\Models\SalesKnowledge::KINDS[$entry->kind];
@endphp

@section('content')
<div class="w-full max-w-3xl space-y-4">
    <a href="{{ route('admin.sales-knowledge.index', ['kind' => $entry->kind]) }}" class="text-sm underline text-slate-600">← {{ $label }}</a>
    <form method="POST" action="{{ $entry->exists ? route('admin.sales-knowledge.update', $entry) : route('admin.sales-knowledge.store') }}" class="bg-white border border-slate-200 rounded-xl p-6 space-y-5">
        @csrf
        @if ($entry->exists) @method('PUT') @endif
        <input type="hidden" name="kind" value="{{ $entry->kind }}">
        <p class="text-sm text-slate-600">{{ $help }}</p>
        <div>
            <label for="title" class="block text-sm font-medium text-slate-700 mb-1">{{ $titleLabel }}</label>
            <input id="title" name="title" value="{{ old('title', $entry->title) }}" maxlength="150" required class="{{ $input }}">
            @error('title')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="body" class="block text-sm font-medium text-slate-700 mb-1">{{ $bodyLabel }}</label>
            <textarea id="body" name="body" rows="12" maxlength="5000" required class="{{ $input }} font-mono text-xs leading-relaxed">{{ old('body', $entry->body) }}</textarea>
            @error('body')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $entry->is_active)) class="rounded border-slate-300"> Used by the assistant
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700">Order
                    <input type="number" name="sort" min="0" max="100000" value="{{ old('sort', $entry->sort) }}" class="w-24 border border-slate-300 rounded-lg px-2 py-1 text-sm">
                </label>
            </div>
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save</button>
        </div>
    </form>
    @if ($entry->exists)
        <form method="POST" action="{{ route('admin.sales-knowledge.destroy', $entry) }}" onsubmit="return confirm(@js('Delete “'.$entry->title.'”?'))">
            @csrf @method('DELETE')
            <button type="submit" class="text-sm text-red-700 underline">Delete</button>
        </form>
    @endif
</div>
@endsection
