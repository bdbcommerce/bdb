@extends('admin.layout')
@section('title', $user->displayName() ?? 'Telegram user')
@section('heading', 'Telegram user')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <a href="{{ route('admin.telegram-users.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; All Telegram users</a>
    @if($replyTo)
        <a href="{{ route('admin.chat-conversations.show', $replyTo) }}#reply-message"
           class="inline-flex items-center gap-2 bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
            </svg>
            Message in Telegram
        </a>
    @endif
</div>

<div class="grid gap-4 lg:grid-cols-3">
    {{-- Info --}}
    <aside class="bg-white border border-slate-200 rounded-xl p-5 h-fit lg:order-2">
        <div class="flex items-center gap-3 mb-4">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full border border-slate-300 text-sm font-semibold text-slate-600 uppercase" aria-hidden="true">
                {{ mb_substr($user->displayName() ?? '?', 0, 1) }}
            </span>
            <div class="min-w-0">
                <p class="text-base font-semibold text-slate-900 truncate">{{ $user->displayName() ?? 'No name yet' }}</p>
                <p class="text-[11px] font-medium mt-0.5 inline-block rounded-full px-2 py-0.5 {{ $user->isRegistered() ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-500' }}">
                    {{ $user->isRegistered() ? 'Registered' : (config('services.telegram.replies') ? 'Registration not finished (step: '.$user->step.')' : ($user->ask_registration ? 'Will be asked to register' : 'Not registered')) }}
                </p>
            </div>
        </div>
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400">Phone</dt>
                <dd class="text-slate-800">
                    @if($user->phone)
                        <a href="tel:{{ $user->phone }}" class="underline">{{ $user->phone }}</a>
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Telegram</dt>
                <dd class="text-slate-800">
                    @if($user->username)
                        <a href="https://t.me/{{ $user->username }}" target="_blank" rel="noopener" class="underline">{{ '@'.$user->username }}</a>
                    @else
                        No @username
                    @endif
                    <span class="block text-xs text-slate-400">ID {{ $user->telegram_id }}</span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Language</dt>
                <dd class="text-slate-800 uppercase">{{ $user->locale ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">First message</dt>
                <dd class="text-slate-800">{{ $user->created_at->format('d.m.Y H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Registered</dt>
                <dd class="text-slate-800">{{ $user->registered_at?->format('d.m.Y H:i') ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Messages / questions / unanswered</dt>
                <dd class="text-slate-800">{{ $stats['messages'] }} / {{ $stats['questions'] }} / {{ $stats['unanswered'] }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Callback requests</dt>
                <dd class="text-slate-800">
                    @forelse($leads as $lead)
                        @if($leadConversations[$lead->id] ?? null)
                            <a href="{{ route('admin.chat-conversations.show', $leadConversations[$lead->id]) }}" class="block underline">{{ $lead->created_at->format('d.m.Y H:i') }} · {{ $lead->phone }}</a>
                        @else
                            <span class="block">{{ $lead->created_at->format('d.m.Y H:i') }} · {{ $lead->phone }}</span>
                        @endif
                    @empty
                        None
                    @endforelse
                </dd>
            </div>
            @if($user->company || $user->notes)
                <div>
                    <dt class="text-xs text-slate-400">Company / notes</dt>
                    <dd class="text-slate-800 whitespace-pre-line break-words">{{ $user->company }}@if($user->company && $user->notes)<br>@endif{{ $user->notes }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-xs text-slate-400">First seen via</dt>
                <dd class="text-slate-800">{{ \App\Models\TelegramUser::SOURCES[$user->source] ?? 'Bot' }}</dd>
            </div>
        </dl>

        {{-- Admin-entered details --}}
        <form method="POST" action="{{ route('admin.telegram-users.update', $user) }}" class="mt-5 pt-5 border-t border-slate-100 space-y-3"
              x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
            @csrf @method('PUT')
            <button type="button" @click="open = ! open" :aria-expanded="open.toString()"
                    class="flex w-full items-center justify-between text-sm font-medium text-slate-900">
                Edit details
                <svg class="w-4 h-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>
            <div x-show="open" x-cloak class="space-y-3">
                @php $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400'; @endphp
                <div>
                    <label for="tg-name" class="block text-xs font-medium text-slate-600 mb-1">Name</label>
                    <input id="tg-name" name="name" maxlength="60" value="{{ old('name', $user->name) }}" placeholder="{{ $user->profile_name }}" class="{{ $input }}">
                    @error('name')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tg-phone" class="block text-xs font-medium text-slate-600 mb-1">Mobile phone</label>
                    <input id="tg-phone" name="phone" type="tel" inputmode="tel" maxlength="20" value="{{ old('phone', $user->phone) }}" placeholder="+998 90 123 45 67" class="{{ $input }}">
                    @error('phone')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tg-company" class="block text-xs font-medium text-slate-600 mb-1">Company</label>
                    <input id="tg-company" name="company" maxlength="120" value="{{ old('company', $user->company) }}" class="{{ $input }}">
                    @error('company')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="tg-locale" class="block text-xs font-medium text-slate-600 mb-1">Language</label>
                    <select id="tg-locale" name="locale" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['uz' => 'Oʻzbekcha', 'ru' => 'Русский', 'en' => 'English'] as $code => $label)
                            <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="tg-notes" class="block text-xs font-medium text-slate-600 mb-1">Notes</label>
                    <textarea id="tg-notes" name="notes" rows="3" maxlength="2000" class="{{ $input }}">{{ old('notes', $user->notes) }}</textarea>
                    @error('notes')<p class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                </div>
                @unless($user->isRegistered())
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="hidden" name="ask_registration" value="0">
                        <input type="checkbox" name="ask_registration" value="1" @checked(old('ask_registration', $user->ask_registration))
                               class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                        <span>
                            Ask for registration info on the next conversation
                            <span class="block text-xs text-slate-400">When the customer next writes to the bot, it asks for language, name and the phone linked to their Telegram. Works only in bot chats.</span>
                        </span>
                    </label>
                @endunless
                <button type="submit" class="w-full bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">Save</button>
            </div>
        </form>
    </aside>

    {{-- Conversation history --}}
    <div class="lg:col-span-2 lg:order-1 space-y-4">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Conversation history</h2>
        @forelse($conversations as $conversation)
            <details class="group bg-white border border-slate-200 rounded-xl" @if($loop->first) open @endif>
                <summary class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center gap-2 text-sm font-medium text-slate-900">
                        <svg class="w-4 h-4 text-slate-400 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                        </svg>
                        {{ $conversation->created_at->format('d.m.Y H:i') }}
                        <span class="text-xs font-normal text-slate-500">· {{ $conversation->message_count }} {{ Str::plural('message', $conversation->message_count) }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="text-xs font-medium px-2 py-0.5 rounded-full border border-slate-300 text-slate-600">{{ $channels[$conversation->channel] ?? $conversation->channel }}</span>
                        @if($conversation->unanswered_count)
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">{{ $conversation->unanswered_count }} unanswered</span>
                        @endif
                        @if($conversation->chat_lead_id)
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-slate-900 text-white">Lead</span>
                        @endif
                    </span>
                </summary>
                <div class="border-t border-slate-100 px-5 py-4 space-y-3">
                    @include('admin.chat-conversations._messages', ['messages' => $conversation->messages])
                    <a href="{{ route('admin.chat-conversations.show', $conversation) }}" class="inline-block text-xs font-medium text-slate-600 underline hover:text-slate-900">Open this conversation</a>
                </div>
            </details>
            @if($loop->last && $total > $conversations->count())
                <p class="text-xs text-slate-500">
                    Showing the latest {{ $conversations->count() }} of {{ $total }} conversations.
                    <a href="{{ route('admin.chat-conversations.index', ['channel' => 'telegram']) }}" class="font-medium underline">All conversations</a>
                </p>
            @endif
        @empty
            <div class="bg-white border border-slate-200 rounded-xl px-6 py-12 text-center">
                <p class="text-sm font-medium text-slate-500">No messages stored</p>
                <p class="text-xs text-slate-400 mt-1">Conversations are kept for {{ \App\Models\ChatConversation::KEEP_DAYS }} days.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
