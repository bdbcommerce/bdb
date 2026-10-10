@extends('admin.layout')
@section('title', 'Client request')
@section('heading', $request->topic ? Str::ucfirst($request->topic) : 'Client request')

@php
    $btn = 'inline-flex items-center justify-center gap-2 text-sm font-medium rounded-lg px-4 py-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400';
    $primary = $btn.' bg-slate-900 text-white hover:bg-slate-700';
    $secondary = $btn.' border border-slate-300 bg-white text-slate-700 hover:bg-slate-50';
    $statusBadge = [
        'open' => 'bg-amber-50 text-amber-800 border-amber-200',
        'answered' => 'bg-white text-slate-600 border-slate-200',
        'resolved' => 'bg-slate-900 text-white border-slate-900',
        'dismissed' => 'bg-slate-50 text-slate-400 border-slate-200',
    ];
@endphp

@section('content')
<a href="{{ route('admin.client-requests.index') }}" class="inline-block mb-4 text-sm text-slate-500 hover:text-slate-900">&larr; All requests</a>

<div class="grid gap-4 lg:grid-cols-3">
    {{-- Questions in this group --}}
    <section class="lg:col-span-2 bg-white border border-slate-200 rounded-xl" aria-labelledby="questions-heading">
        <h2 id="questions-heading" class="px-5 pt-5 text-xs font-semibold text-slate-500 uppercase tracking-wide">
            {{ $group->count() }} {{ Str::plural('question', $group->count()) }} on this topic
        </h2>
        <ul class="divide-y divide-slate-100 mt-3" role="list">
            @foreach($group as $item)
                <li class="px-5 py-3" x-data="{ open: false }">
                    <div class="flex flex-wrap items-start gap-x-3 gap-y-1">
                        <p class="flex-1 min-w-0 text-sm text-slate-900 break-words">{{ $item->question }}</p>
                        <span class="shrink-0 text-xs font-medium px-2 py-0.5 rounded-full border {{ $statusBadge[$item->status] ?? '' }}">{{ $statuses[$item->status] ?? $item->status }}</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1">
                        {{ $item->channel === 'telegram' ? 'Telegram' : 'Website' }}
                        · <span class="uppercase">{{ $item->locale }}</span>
                        · {{ $item->created_at->format('d.m.Y H:i') }}
                        @if($item->notified_at) · answer sent {{ $item->notified_at->format('d.m H:i') }} @endif
                        @if($item->conversation)
                            · <a href="{{ route('admin.chat-conversations.show', $item->conversation) }}" class="underline hover:text-slate-700">conversation</a>
                        @endif
                        @if($item->ai_reply)
                            · <button type="button" @click="open = ! open" :aria-expanded="open.toString()" class="underline hover:text-slate-700">AI reply</button>
                        @endif
                    </p>
                    @if($item->ai_reply)
                        <p x-show="open" x-cloak class="mt-2 text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 whitespace-pre-line">{{ $item->ai_reply }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Actions --}}
    <aside class="space-y-4">
        @if($openCount)
            <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Answer {{ $openCount }} open {{ Str::plural('question', $openCount) }}</h2>
                <p class="text-xs text-slate-500">Write the answer once as a knowledge entry. The assistant will use it from now on, and the AI links other open questions it answers.</p>
                <a href="{{ route('admin.knowledge.create', ['client_request' => $request->id, 'draft' => 1]) }}" class="{{ $primary }} w-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/>
                    </svg>
                    Draft answer with AI
                </a>
                <a href="{{ route('admin.knowledge.create', ['client_request' => $request->id]) }}" class="{{ $secondary }} w-full">Write answer myself</a>

                @if($entries->isNotEmpty())
                    <form method="POST" action="{{ route('admin.client-requests.link', $request) }}" class="pt-3 border-t border-slate-100 space-y-2">
                        @csrf
                        <label for="knowledge_entry_id" class="block text-xs font-medium text-slate-700">Or answer with an existing entry</label>
                        <select id="knowledge_entry_id" name="knowledge_entry_id" required
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                            <option value="">Choose…</option>
                            @foreach($entries as $option)
                                <option value="{{ $option->id }}">{{ Str::limit($option->getTranslation('question', 'en', true), 70) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="{{ $secondary }} w-full">Link answer</button>
                    </form>
                @endif

                <form method="POST" action="{{ route('admin.client-requests.dismiss', $request) }}"
                      onsubmit="return confirm('Dismiss these questions? They will leave the open list.')">
                    @csrf
                    <button type="submit" class="w-full text-xs text-slate-400 hover:text-red-600 py-1">Dismiss (spam or not a real question)</button>
                </form>
            </div>
        @endif

        @if($entry)
            <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Answer</h2>
                    <a href="{{ route('admin.knowledge.edit', $entry) }}" class="text-xs text-slate-500 underline hover:text-slate-900">Edit entry</a>
                </div>
                <p class="text-sm font-medium text-slate-900">{{ $entry->getTranslation('question', 'en', true) }}</p>
                <p class="text-sm text-slate-600 whitespace-pre-line">{{ $entry->getTranslation('answer', 'en', true) }}</p>

                @if($pendingCustomers)
                    <form method="POST" action="{{ route('admin.client-requests.notify', $request) }}" class="pt-3 border-t border-slate-100 space-y-2"
                          onsubmit="return confirm('Send this answer to {{ $pendingCustomers }} Telegram {{ Str::plural('customer', $pendingCustomers) }}?')">
                        @csrf
                        <input type="hidden" name="knowledge_entry_id" value="{{ $entry->id }}">
                        <p class="text-xs text-slate-500">{{ $pendingCustomers }} Telegram {{ Str::plural('customer', $pendingCustomers) }} asked questions this entry answers (here or in groups the AI matched) and haven't got it yet. Each gets it in their own language.</p>
                        <button type="submit" class="{{ $primary }} w-full">Send answer to {{ $pendingCustomers }} {{ Str::plural('customer', $pendingCustomers) }}</button>
                    </form>
                @endif
            </div>
        @endif
    </aside>
</div>
@endsection
