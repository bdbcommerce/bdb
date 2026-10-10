@extends('admin.layout')
@section('title', 'Conversations')
@section('heading', 'Conversations')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500">Every chat with the AI assistant, on the website and in Telegram. Kept for {{ $keepDays }} days.</p>
    <div class="flex flex-wrap gap-2">
        @php
            $tab = fn (bool $active) => 'text-xs font-medium rounded-md px-3 py-1.5 transition-colors '
                .($active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
            $query = fn (?string $f, ?string $c) => array_filter(['filter' => $f === 'all' ? null : $f, 'channel' => $c]);
        @endphp
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1" aria-label="Conversation type">
            <a href="{{ route('admin.chat-conversations.index', $query($filter, null)) }}"
               @if(! $channel) aria-current="true" @endif class="{{ $tab(! $channel) }}">All types</a>
            @foreach($channels as $key => $label)
                <a href="{{ route('admin.chat-conversations.index', $query($filter, $key)) }}"
                   @if($channel === $key) aria-current="true" @endif class="{{ $tab($channel === $key) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1" aria-label="Filter conversations">
            @foreach($filters as $key => $label)
                <a href="{{ route('admin.chat-conversations.index', $query($key, $channel)) }}"
                   @if($filter === $key) aria-current="true" @endif class="{{ $tab($filter === $key) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</div>

@if($filter === 'unanswered')
    <p class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 mb-4">
        The assistant had no answer for at least one question in these chats.
        Add the missing information as a <a href="{{ route('admin.knowledge.create') }}" class="font-medium text-slate-900 underline">knowledge entry</a>.
    </p>
@endif

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($conversations->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No conversations</p>
            <p class="text-xs text-slate-400 mt-1">Chats appear here as soon as a visitor asks the assistant something.</p>
        </div>
    @else
        <div class="divide-y divide-slate-100">
            @foreach($conversations as $conversation)
                @php
                    $userMessages = collect($conversation->messages)->where('role', 'user');
                    $first = $userMessages->first();
                    $questions = $userMessages->whereNotNull('content')->count();
                    $media = $userMessages->count() - $questions;
                @endphp
                <a href="{{ route('admin.chat-conversations.show', $conversation) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-slate-50">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-slate-800 truncate">{{ $first['content'] ?? (isset($first['type']) ? '['.str_replace('_', ' ', ucfirst($first['type'])).']' : '—') }}</p>
                        <p class="text-xs text-slate-400 mt-0.5 truncate">
                            {{ $questions }} {{ Str::plural('question', $questions) }}
                            @if($media) · {{ $media }} {{ Str::plural('file', $media) }} @endif
                            · <span class="uppercase">{{ $conversation->locale }}</span>
                            @if($conversation->telegramUser?->name)
                                · {{ $conversation->telegramUser->name }}
                            @elseif($conversation->page && $conversation->channel === 'web')
                                · {{ $conversation->page }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full border {{ $conversation->channel === 'telegram' ? 'border-slate-400 text-slate-700' : 'border-slate-200 text-slate-500' }}">{{ $channels[$conversation->channel] ?? ucfirst($conversation->channel) }}</span>
                        @if($conversation->unanswered_count)
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">{{ $conversation->unanswered_count }} unanswered</span>
                        @endif
                        @if($conversation->chat_lead_id)
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-slate-900 text-white">Lead</span>
                        @endif
                        <span class="text-xs text-slate-400 w-24 text-right">{{ $conversation->updated_at->diffForHumans() }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
<div class="mt-4">{{ $conversations->links() }}</div>
@endsection
