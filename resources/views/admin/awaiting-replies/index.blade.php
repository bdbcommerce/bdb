@extends('admin.layout')
@section('title', 'Waiting for reply')
@section('heading', 'Waiting for reply')

@section('content')
@php
    $tab = fn (bool $active) => 'text-xs font-medium rounded-md px-3 py-1.5 transition-colors whitespace-nowrap '
        .($active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
    $query = fn (?string $c, string $s) => array_filter(['channel' => $c, 'sort' => $s === 'oldest' ? null : $s]);
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500 max-w-xl">
        Telegram chats where the customer wrote last and nobody has answered yet.
        A chat leaves the list when you reply (from your Telegram for the Premium account, or from the conversation page for bot chats)
        or when you mark it as answered.
    </p>
    <div class="flex flex-wrap gap-2">
        <nav class="flex flex-wrap gap-1 bg-slate-100 rounded-lg p-1" aria-label="Chat type">
            <a href="{{ route('admin.awaiting-replies.index', $query(null, $sort)) }}" @if(! $channel) aria-current="true" @endif class="{{ $tab(! $channel) }}">
                All <span class="text-slate-400">{{ $counts->sum() }}</span>
            </a>
            @foreach($channels as $key => $label)
                <a href="{{ route('admin.awaiting-replies.index', $query($key, $sort)) }}" @if($channel === $key) aria-current="true" @endif class="{{ $tab($channel === $key) }}">
                    {{ $label }} <span class="text-slate-400">{{ $counts[$key] ?? 0 }}</span>
                </a>
            @endforeach
        </nav>
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1" aria-label="Sort">
            @foreach($sorts as $key => $label)
                <a href="{{ route('admin.awaiting-replies.index', $query($channel, $key)) }}" @if($sort === $key) aria-current="true" @endif class="{{ $tab($sort === $key) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</div>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($conversations->isEmpty())
        <div class="px-6 py-16 text-center">
            <svg class="mx-auto w-8 h-8 text-slate-300 mb-2" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
            <p class="text-sm font-medium text-slate-500">Nobody is waiting</p>
            <p class="text-xs text-slate-400 mt-1">Every customer has an answer.</p>
        </div>
    @else
        <ul class="divide-y divide-slate-100">
            @foreach($conversations as $conversation)
                @php
                    $last = collect($conversation->messages)->reject(fn ($m) => ! empty($m['deleted']) || ! empty($m['late_edit']))->last() ?? [];
                    $tgUser = $conversation->channel === 'telegram' ? $conversation->telegramUser : null;
                    $name = $conversation->title ?? $tgUser?->displayName() ?? 'Telegram '.$conversation->external_id;
                    $username = $tgUser?->username;
                    // The customer's own messages in a row at the end: how many are unanswered.
                    $unread = 0;
                    foreach (array_reverse($conversation->messages ?? []) as $m) {
                        if (! empty($m['deleted'])) { continue; }
                        if (! empty($m['late_edit'])) { continue; }
                        if (($m['role'] ?? null) !== 'user') { break; }
                        $unread++;
                    }
                @endphp
                <li class="flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <a href="{{ route('admin.chat-conversations.show', $conversation) }}" class="min-w-0 flex-1 group">
                        <p class="text-sm font-medium text-slate-900 truncate group-hover:underline">{{ $name }}</p>
                        <p class="text-sm text-slate-600 truncate">
                            {{ $last['content'] ?? '['.str_replace('_', ' ', ucfirst($last['type'] ?? 'message')).']' }}
                        </p>
                        <p class="text-xs text-slate-400 mt-0.5">
                            {{ $channels[$conversation->channel] ?? $conversation->channel }}
                            @if($conversation->channel === 'business' && $conversation->page) · {{ $conversation->page }} @endif
                            · {{ $unread }} {{ Str::plural('message', $unread) }} without answer
                        </p>
                    </a>
                    <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                        <span class="text-xs font-medium text-slate-700 sm:w-28 sm:text-right" title="{{ $conversation->last_message_at?->format('d.m.Y H:i') }}">
                            {{ $conversation->last_message_at ? 'waiting '.$conversation->last_message_at->diffForHumans(null, true) : '' }}
                        </span>
                        <a href="{{ route('admin.chat-conversations.show', $conversation) }}"
                           class="text-xs font-medium text-white bg-slate-900 rounded-lg px-3 py-1.5 hover:bg-slate-700">Open</a>
                        <form method="POST" action="{{ route('admin.chat-conversations.handled', $conversation) }}">
                            @csrf
                            <button class="text-xs font-medium text-slate-600 border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-50">Mark answered</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
<div class="mt-4">{{ $conversations->links() }}</div>
@endsection
