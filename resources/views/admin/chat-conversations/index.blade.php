@extends('admin.layout')
@section('title', $telegramOnly ? 'Telegram bot chats' : 'Conversations')
@section('heading', $telegramOnly ? 'Telegram bot chats' : 'Conversations')

@section('content')
@php
    $chip = fn (bool $active) => 'shrink-0 text-xs font-medium rounded-full px-3 py-1.5 border transition-colors '
        .($active ? 'bg-[#d9fdd3] border-[#b5e8ac] text-emerald-900' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900');
    $query = fn (?string $f, ?string $c) => array_filter(['filter' => $f === 'all' ? null : $f, 'channel' => $c]);
    $tz = config('app.timezone');
    // Messenger-style time: today → 14:05, yesterday, this week → weekday, older → date.
    $when = function ($at) use ($tz) {
        $at = $at->copy()->timezone($tz);

        return match (true) {
            $at->isToday() => $at->format('H:i'),
            $at->isYesterday() => 'Yesterday',
            $at->gt(now($tz)->subDays(6)->startOfDay()) => $at->format('l'),
            default => $at->format('d.m.Y'),
        };
    };
@endphp

<div class="max-w-4xl bg-white border border-slate-200 rounded-xl overflow-hidden">
    {{-- Filters as messenger chips --}}
    <div class="px-3 sm:px-4 pt-3 pb-2 border-b border-slate-200 space-y-2">
        <p class="text-xs text-slate-500">
            @if($telegramOnly)
                Chats with the Telegram bot. Premium-account and group chats are under Conversations. Kept for {{ $keepDays }} days.
            @else
                Website and Telegram bot chats with the AI assistant, plus recorded Telegram Business and group chats. One row per chat. Kept for {{ $keepDays }} days.
            @endif
        </p>
        @unless($telegramOnly)
            <nav class="flex gap-1.5 overflow-x-auto pb-0.5" aria-label="Conversation type">
                <a href="{{ route($indexRoute, $query($filter, null)) }}"
                   @if(! $channel) aria-current="true" @endif class="{{ $chip(! $channel) }}">All types</a>
                @foreach($channels as $key => $label)
                    <a href="{{ route($indexRoute, $query($filter, $key)) }}"
                       @if($channel === $key) aria-current="true" @endif class="{{ $chip($channel === $key) }}">{{ $label }}</a>
                @endforeach
            </nav>
        @endunless
        <nav class="flex gap-1.5 overflow-x-auto pb-0.5" aria-label="Filter conversations">
            @foreach($filters as $key => $label)
                <a href="{{ route($indexRoute, $query($key, $channel)) }}"
                   @if($filter === $key) aria-current="true" @endif class="{{ $chip($filter === $key) }}">{{ $label }}</a>
            @endforeach
        </nav>
        @if($filter === 'unanswered')
            <p class="text-xs text-slate-500">
                The assistant had no answer for at least one question in these chats.
                Add the missing information as a <a href="{{ route('admin.knowledge.create') }}" class="font-medium text-slate-900 underline">knowledge entry</a>.
            </p>
        @endif
    </div>

    @if($conversations->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No conversations</p>
            <p class="text-xs text-slate-500 mt-1">Chats appear here as soon as a visitor asks the assistant something.</p>
        </div>
    @else
        <ul>
            @foreach($conversations as $conversation)
                @php
                    $all = collect($conversation->messages);
                    $userMessages = $all->where('role', 'user');
                    $recorded = in_array($conversation->channel, ['business', 'group'], true);
                    // Preview: the chat's first real question (not /start); recorded chats: the latest message.
                    $last = $recorded ? $all->last() : ($userMessages->first(fn ($m) => empty($m['aside'])) ?? $userMessages->first());
                    $lastRole = $last['role'] ?? 'user';
                    $ours = in_array($lastRole, ['assistant', 'admin', 'owner', 'bot'], true);
                    $prefix = match (true) {
                        $lastRole === 'assistant' => 'Assistant',
                        $lastRole === 'bot' => 'Bot',
                        $lastRole === 'admin' => $last['author'] ?? 'BDB team',
                        $lastRole === 'owner' => 'Owner',
                        isset($last['sender']) => $last['sender'],
                        default => null,
                    };
                    $preview = $last['content'] ?? (isset($last['type']) ? '['.str_replace('_', ' ', ucfirst($last['type'])).']' : '—');
                    $first = $last;
                    $questions = $userMessages->whereNotNull('content')->filter(fn ($m) => empty($m['aside']))->count();
                    $media = $userMessages->whereNull('content')->filter(fn ($m) => empty($m['aside']))->count();
                    $name = $conversation->chatName();
                    $at = $conversation->last_message_at ?? $conversation->updated_at;
                    // Only the chat's newest session can be waiting (as on Waiting for reply); filters may pick an older one.
                    $waiting = $conversation->awaiting_reply && $conversation->latestSession()->is($conversation);
                @endphp
                <li class="border-b border-slate-100 last:border-b-0">
                    <a href="{{ route('admin.chat-conversations.show', $conversation) }}" class="flex items-center gap-3 px-3 sm:px-4 py-3 hover:bg-slate-50 focus-visible:bg-slate-50">
                        <span class="w-12 h-12 rounded-full bg-slate-200 text-slate-700 text-base font-semibold flex items-center justify-center shrink-0" aria-hidden="true">
                            @if($conversation->channel === 'group')
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                            @else
                                {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <p class="text-[15px] font-semibold text-slate-900 truncate">{{ $name }}</p>
                                <span title="Source" class="shrink-0 text-[11px] text-slate-500">{{ $channels[$conversation->channel] ?? ucfirst($conversation->channel) }}</span>
                                <time class="ml-auto shrink-0 text-xs {{ $waiting ? 'text-emerald-700 font-semibold' : 'text-slate-500' }}"
                                      datetime="{{ $at->toIso8601String() }}" title="{{ $at->copy()->timezone($tz)->format('d.m.Y H:i') }}">{{ $when($at) }}</time>
                            </div>
                            <div class="flex items-center gap-2 mt-0.5">
                                <p class="text-sm text-slate-600 truncate flex-1 min-w-0">
                                    @if($ours)
                                        <svg class="inline w-4 h-4 -mt-0.5 text-slate-500" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12.75 4.5 4.5 9-10.5M10.5 15.75l1.5 1.5 9.75-10.5"/></svg>
                                    @endif
                                    @if($prefix)<span class="text-slate-500">{{ $prefix }}:</span>@endif
                                    {{ \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', (string) $preview), 120) }}
                                </p>
                                @if($conversation->chat_lead_id)
                                    <span class="shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full bg-slate-900 text-white">Lead</span>
                                @endif
                                @if($conversation->unanswered_count)
                                    <span class="shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full bg-amber-100 text-amber-800" title="Questions the assistant could not answer">{{ $conversation->unanswered_count }} unanswered</span>
                                @endif
                                @if($waiting)
                                    <span class="shrink-0 w-2.5 h-2.5 rounded-full bg-emerald-600" title="Waiting for reply"></span>
                                    <span class="sr-only">Waiting for reply</span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5 truncate">
                                @if($recorded)
                                    {{ $conversation->message_count }} {{ Str::plural('message', $conversation->message_count) }}
                                    @if($conversation->page) · {{ $conversation->page }} @endif
                                @else
                                    {{ $questions }} {{ Str::plural('question', $questions) }}
                                    @if($media) · {{ $media }} {{ Str::plural('file', $media) }} @endif
                                    · <span class="uppercase">{{ $conversation->locale }}</span>
                                    @if($conversation->page && $conversation->channel === 'web') · {{ $conversation->page }} @endif
                                @endif
                            </p>
                            @if($conversation->channel !== 'web' && $conversation->external_id)
                                @php
                                    // Bot and Premium-account chats: the chat ID is the customer's own Telegram ID.
                                    $fromId = $first['sender_id'] ?? (in_array($conversation->channel, ['telegram', 'business'], true) && in_array($first['role'] ?? null, ['user', null], true) ? $conversation->external_id : null);
                                @endphp
                                <p class="text-[11px] text-slate-500 mt-0.5 truncate font-mono" data-ids>
                                    Chat ID {{ $conversation->external_id }}@if($fromId) · From ID {{ $fromId }}@endif
                                </p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    @if($conversations->total())
        <div class="flex flex-wrap items-center justify-between gap-3 px-3 sm:px-4 py-3 border-t border-slate-200 bg-slate-50">
            <p class="text-xs text-slate-500">
                {{ $conversations->firstItem() }}–{{ $conversations->lastItem() }} of {{ $conversations->total() }} {{ Str::plural('chat', $conversations->total()) }}
            </p>
            @if($conversations->hasPages())
                <div class="text-sm">{{ $conversations->onEachSide(1)->links() }}</div>
            @endif
        </div>
    @endif
</div>
@endsection
