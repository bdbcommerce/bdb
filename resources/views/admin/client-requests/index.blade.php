@extends('admin.layout')
@section('title', 'Client requests')
@section('heading', 'Client requests')

@php
    $tab = fn (bool $active) => 'text-xs font-medium rounded-md px-3 py-1.5 transition-colors '
        .($active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
    $query = fn (?string $s, ?string $c) => array_filter(['status' => $s === 'open' ? null : $s, 'channel' => $c]);
    $statusBadge = [
        'open' => 'bg-amber-50 text-amber-800 border-amber-200',
        'answered' => 'bg-white text-slate-600 border-slate-200',
        'resolved' => 'bg-slate-900 text-white border-slate-900',
        'dismissed' => 'bg-slate-50 text-slate-400 border-slate-200',
    ];
@endphp

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500 max-w-xl">
        Customer questions from the website and Telegram, grouped by topic by the AI. Answer an open group once with a
        knowledge entry, and the assistant uses it for everyone who asks the same thing.
    </p>
    <div class="flex flex-wrap gap-2 min-w-0 max-w-full">
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1 max-w-full overflow-x-auto whitespace-nowrap" aria-label="Request status">
            @foreach($statuses + ['all' => 'All'] as $key => $label)
                <a href="{{ route('admin.client-requests.index', $query($key, $channel)) }}"
                   @if($status === $key) aria-current="true" @endif class="{{ $tab($status === $key) }}">
                    {{ $label }}@if(isset($counts[$key])) <span class="text-slate-400">{{ $counts[$key] }}</span>@endif
                </a>
            @endforeach
        </nav>
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1" aria-label="Request type">
            <a href="{{ route('admin.client-requests.index', $query($status, null)) }}"
               @if(! $channel) aria-current="true" @endif class="{{ $tab(! $channel) }}">All types</a>
            @foreach($channels as $key => $label)
                <a href="{{ route('admin.client-requests.index', $query($status, $key)) }}"
                   @if($channel === $key) aria-current="true" @endif class="{{ $tab($channel === $key) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</div>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($groups->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No requests here</p>
            <p class="text-xs text-slate-400 mt-1">Questions appear as soon as customers ask the assistant something.</p>
        </div>
    @else
        <ul class="divide-y divide-slate-100" role="list">
            @foreach($groups as $group)
                <li>
                    <a href="{{ route('admin.client-requests.show', $group->first) }}" class="flex items-start gap-4 px-4 py-3 hover:bg-slate-50">
                        <span class="shrink-0 w-10 h-10 rounded-lg bg-slate-100 text-slate-900 text-sm font-semibold flex items-center justify-center"
                              title="{{ $group->count }} {{ Str::plural('question', $group->count) }}">{{ $group->count }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $group->topic ? Str::ucfirst($group->topic) : $group->samples->first() }}</p>
                            @foreach($group->samples->take($group->topic ? 2 : 0) as $sample)
                                <p class="text-xs text-slate-500 truncate">“{{ $sample }}”</p>
                            @endforeach
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ $group->channels->map(fn ($c) => $channels[$c] ?? $c)->implode(', ') }}
                                · <span class="uppercase">{{ $group->locales->implode(', ') }}</span>
                                · {{ $group->latest->diffForHumans() }}
                            </p>
                        </div>
                        <span class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full border {{ $statusBadge[$group->first->status] ?? '' }}">
                            {{ $statuses[$group->first->status] ?? $group->first->status }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
<div class="mt-4">{{ $groups->links() }}</div>
@endsection
