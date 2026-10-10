@extends('admin.layout')
@section('title', 'Conversation')
@section('heading', 'Conversation')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <a href="{{ route('admin.chat-conversations.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; All conversations</a>
    <form method="POST" action="{{ route('admin.chat-conversations.destroy', $conversation) }}" onsubmit="return confirm('Delete this conversation?')">
        @csrf @method('DELETE')
        <button class="text-xs text-slate-400 hover:text-red-600">Delete</button>
    </form>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-5 space-y-3">
        @foreach($conversation->messages as $m)
            @if($m['role'] === 'user' && ! isset($m['content']))
                {{-- Telegram media: only type + file ID are stored; the file is fetched from Telegram on demand --}}
                @php
                    $type = $m['type'] ?? 'other';
                    $url = ! empty($m['file_id']) ? route('admin.telegram.media', $m['file_id']) : null;
                    $player = match ($type) {
                        'photo', 'sticker' => 'image',
                        'video', 'animation', 'video_note' => 'video',
                        'voice', 'audio' => 'audio',
                        default => null,
                    };
                @endphp
                <div class="flex justify-end" x-data="{ shown: false }">
                    <div class="max-w-[85%] border border-slate-300 bg-white text-sm rounded-2xl rounded-tr-sm px-3.5 py-2.5 space-y-2">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/>
                            </svg>
                            <span class="font-medium text-slate-800">{{ str_replace('_', ' ', ucfirst($type)) }}</span>
                            @if($url)
                                @if($player)
                                    <button type="button" x-show="! shown" @click="shown = true"
                                            class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Load</button>
                                @endif
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Open<span class="sr-only"> {{ $type }} in a new tab</span></a>
                            @else
                                <span class="text-xs text-slate-400">not stored</span>
                            @endif
                        </div>
                        @if($url && $player)
                            <template x-if="shown">
                                <div>
                                    @if($player === 'image')
                                        <img src="{{ $url }}" alt="{{ ucfirst($type) }} sent by the customer" class="max-h-80 rounded-lg border border-slate-200">
                                    @elseif($player === 'video')
                                        <video src="{{ $url }}" controls class="max-h-80 rounded-lg border border-slate-200"></video>
                                    @else
                                        <audio src="{{ $url }}" controls class="w-64 max-w-full"></audio>
                                    @endif
                                </div>
                            </template>
                        @endif
                    </div>
                </div>
            @elseif($m['role'] === 'user')
                <div class="flex justify-end">
                    <p class="max-w-[85%] bg-slate-900 text-white text-sm rounded-2xl rounded-tr-sm px-3.5 py-2.5 whitespace-pre-line">{{ $m['content'] }}</p>
                </div>
            @elseif($m['role'] === 'admin')
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 mb-1">BDB team · {{ $m['author'] ?? 'Admin' }}</p>
                    <p class="max-w-[85%] bg-white border-2 border-slate-900 text-slate-900 text-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 whitespace-pre-line">{{ $m['content'] }}</p>
                </div>
            @else
                <div>
                    <p class="max-w-[85%] bg-slate-50 border text-sm rounded-2xl rounded-tl-sm px-3.5 py-2.5 whitespace-pre-line {{ ($m['answered'] ?? true) ? 'border-slate-200 text-slate-800' : 'border-amber-300 text-slate-800' }}">{{ $m['content'] }}</p>
                    @unless($m['answered'] ?? true)
                        <p class="text-xs text-amber-700 mt-1">
                            No answer in the site data.
                            <a href="{{ route('admin.knowledge.create') }}" class="font-medium underline">Add a knowledge entry</a>
                        </p>
                    @endunless
                </div>
            @endif
        @endforeach

        @if($conversation->channel === 'telegram' && $conversation->external_id)
            @if (session('telegram_error'))
                <p role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('telegram_error') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.chat-conversations.reply', $conversation) }}"
                  class="border-t border-slate-200 pt-4 mt-2 space-y-2">
                @csrf
                <label for="reply-message" class="block text-sm font-medium text-slate-700">Message the customer in Telegram</label>
                <textarea id="reply-message" name="message" rows="3" maxlength="4000" required
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                          placeholder="Sent from the bot to {{ $conversation->telegramUser?->name ?? 'the customer' }}">{{ old('message') }}</textarea>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-slate-400">The customer sees it as a message from the bot. It is not checked by the AI.</p>
                    <button type="submit"
                            class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">
                        Send
                    </button>
                </div>
            </form>
        @endif
    </div>

    <aside class="bg-white border border-slate-200 rounded-xl p-5 h-fit">
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400">Started</dt>
                <dd class="text-slate-800">{{ $conversation->created_at->format('d.m.Y H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Channel</dt>
                <dd class="text-slate-800">{{ $conversation->channel === 'telegram' ? 'Telegram bot' : 'Website' }}</dd>
            </div>
            @if($conversation->telegramUser)
                @php $tgUser = $conversation->telegramUser; @endphp
                <div>
                    <dt class="text-xs text-slate-400">Telegram customer</dt>
                    <dd class="text-slate-800">
                        {{ $tgUser->name ?? 'Not registered yet' }}@if($tgUser->phone), {{ $tgUser->phone }}@endif
                        @if($tgUser->username)
                            <br><a href="https://t.me/{{ $tgUser->username }}" target="_blank" rel="noopener" class="underline">{{ '@'.$tgUser->username }}</a>
                        @endif
                    </dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-slate-400">Language</dt>
                <dd class="text-slate-800 uppercase">{{ $conversation->locale }}</dd>
            </div>
            @if($conversation->page)
                <div>
                    <dt class="text-xs text-slate-400">Page</dt>
                    <dd class="text-slate-800">{{ $conversation->page }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-slate-400">Questions / unanswered</dt>
                <dd class="text-slate-800">{{ collect($conversation->textMessages())->where('role', 'user')->count() }} / {{ $conversation->unanswered_count }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Callback request</dt>
                <dd class="text-slate-800">
                    @if($conversation->lead)
                        <a href="{{ route('admin.chat-leads.index') }}#lead-{{ $conversation->lead->id }}" class="underline">{{ $conversation->lead->name }}, {{ $conversation->lead->phone }}</a>
                    @else
                        None
                    @endif
                </dd>
            </div>
        </dl>
    </aside>
</div>
@endsection
