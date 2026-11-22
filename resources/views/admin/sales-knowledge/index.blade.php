@extends('admin.layout')
@section('title', 'Sales knowledge')
@section('heading', 'Sales assistant — Knowledge')

@php [$label, $help] = \App\Models\SalesKnowledge::KINDS[$kind]; @endphp

@section('content')
<div class="w-full space-y-6">
    <p class="text-sm text-slate-600">Everything the sales assistant may use. It never adds facts of its own: when a list is empty or something is not written here, it tells the customer the manager will confirm.</p>

    <nav class="flex flex-wrap gap-2" aria-label="Knowledge kinds">
        @foreach (\App\Models\SalesKnowledge::KINDS as $key => [$name])
            <a href="{{ route('admin.sales-knowledge.index', ['kind' => $key]) }}" @if($key === $kind) aria-current="page" @endif
               class="text-sm rounded-lg px-3 py-1.5 border {{ $key === $kind ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-700 hover:border-slate-500' }}">
                {{ $name }} <span class="{{ $key === $kind ? 'text-slate-300' : 'text-slate-400' }}">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <div class="px-6 py-4 flex flex-wrap items-start justify-between gap-3 border-b border-slate-100">
            <p class="text-sm text-slate-600 max-w-2xl">{{ $help }}</p>
            <a href="{{ route('admin.sales-knowledge.create', ['kind' => $kind]) }}" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Add</a>
        </div>
        @forelse ($entries as $entry)
            <div class="px-6 py-4 border-b border-slate-100 last:border-0 {{ $entry->is_active ? '' : 'opacity-60' }}" data-entry="{{ $entry->id }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-medium text-slate-900">{{ $entry->title }}@unless($entry->is_active)<span class="ml-2 text-xs text-slate-500">off</span>@endunless</h3>
                    <a href="{{ route('admin.sales-knowledge.edit', $entry) }}" class="text-xs underline text-slate-600">Edit</a>
                </div>
                <p class="mt-1 text-sm text-slate-600 whitespace-pre-line break-words">{{ \Illuminate\Support\Str::limit($entry->body, 600) }}</p>
            </div>
        @empty
            <p class="px-6 py-6 text-sm {{ in_array($kind, ['marking', 'software', 'device'], true) ? 'text-amber-700' : 'text-slate-500' }}">
                Empty. {{ in_array($kind, ['marking', 'software', 'device'], true) ? 'Please fill this in — until then the assistant says the manager will confirm.' : '' }}
            </p>
        @endforelse
    </div>
</div>
@endsection
