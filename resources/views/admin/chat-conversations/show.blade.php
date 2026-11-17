@extends('admin.layout')
@section('title', 'Conversation')
@section('heading', 'Conversation')
@section('inline-flash', '1') {{-- flash shown inside the chat panel, so it never pushes the composer off-screen --}}

@section('content')
@php
    $recordedChat = in_array($conversation->channel, ['business', 'group'], true);
    $tgUser = $conversation->channel === 'telegram' ? $conversation->telegramUser : null;
    $channelLabel = \App\Models\ChatConversation::CHANNELS[$conversation->channel] ?? ucfirst($conversation->channel);
    $chatName = $conversation->chatName();
    $fromIds = $conversation->fromIds();
    $canReply = $conversation->channel === 'telegram' && $conversation->external_id;
@endphp

{{-- Chat panel fills the screen below the admin header (4rem) and the page padding; messages scroll inside it. --}}
<div class="lg:flex lg:gap-4 lg:h-[calc(100dvh-7rem)]">
    <section class="flex flex-col h-[calc(100dvh-6rem)] sm:h-[calc(100dvh-7rem)] lg:h-full min-h-[26rem] flex-1 min-w-0 bg-white border border-slate-200 rounded-xl overflow-hidden"
             aria-label="Conversation with {{ $chatName }}">
        {{-- Chat header --}}
        <div class="flex items-center gap-3 px-3 sm:px-4 py-2.5 bg-slate-50 border-b border-slate-200 shrink-0">
            <a href="{{ route('admin.chat-conversations.index') }}" class="p-1.5 -ml-1 rounded-lg text-slate-500 hover:text-slate-900 hover:bg-slate-200/60" title="All conversations">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                <span class="sr-only">All conversations</span>
            </a>
            <span class="w-10 h-10 rounded-full bg-slate-300 text-slate-700 text-sm font-semibold flex items-center justify-center shrink-0" aria-hidden="true">
                {{ mb_strtoupper(mb_substr($chatName, 0, 1)) }}
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900 truncate">{{ $chatName }}</p>
                <p class="text-xs text-slate-500 truncate">
                    {{ $channelLabel }}
                    @if($tgUser?->username) · {{ '@'.$tgUser->username }} @endif
                    @if($conversation->external_id) · <span class="font-mono">{{ $conversation->external_id }}</span> @endif
                </p>
            </div>
            @if($latest->awaiting_reply)
                <form method="POST" action="{{ route('admin.chat-conversations.handled', $latest) }}">
                    @csrf
                    <button class="text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-100 whitespace-nowrap">Mark as answered</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.chat-conversations.destroy', $conversation) }}" onsubmit="return confirm('Delete this conversation?')">
                @csrf @method('DELETE')
                <button class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50" title="Delete">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                    <span class="sr-only">Delete</span>
                </button>
            </form>
        </div>

        @if (session('success') || $errors->any())
            <div class="shrink-0 px-3 sm:px-4 py-2 border-b text-sm {{ $errors->any() ? 'bg-red-50 border-red-200 text-red-800' : 'bg-green-50 border-green-200 text-green-800' }}" role="{{ $errors->any() ? 'alert' : 'status' }}">
                @if (session('success'))<p>{{ session('success') }}</p>@endif
                @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif

        {{-- Messages: the only part that scrolls; opens at the newest message --}}
        <div class="flex-1 min-h-0 overflow-y-auto bg-[#efeae2] px-3 sm:px-[6%] py-4"
             x-data x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)" data-chat-scroll>
            @if($history['older'] && $days < $maxDays)
                <div class="flex justify-center mb-3" data-history-more>
                    <a href="{{ route('admin.chat-conversations.show', [$conversation, 'days' => $days + 7]) }}"
                       class="text-xs font-medium text-slate-700 bg-white/90 hover:bg-white rounded-full px-4 py-1.5 shadow-sm">
                        Show earlier messages
                    </a>
                </div>
                <p class="text-center text-[11px] text-slate-500 mb-3">Showing the last {{ $days }} days of this chat</p>
            @endif
            @if(empty($history['messages']))
                <p class="text-center text-sm text-slate-500 py-10">No messages</p>
            @else
                @include('admin.chat-conversations._messages', ['messages' => $history['messages']])
            @endif
        </div>

        {{-- Composer --}}
        @if($canReply)
            <form method="POST" action="{{ route('admin.chat-conversations.reply', $latest) }}"
                  class="shrink-0 bg-slate-50 border-t border-slate-200 px-3 sm:px-4 py-2.5"
                  x-data="{
                      message: @js(old('message', '')),
                      loading: false,
                      error: '',
                      grow() { const t = this.$refs.message; t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight, 160) + 'px'; },
                      async suggest() {
                          this.loading = true; this.error = '';
                          try {
                              const res = await fetch(@js(route('admin.chat-conversations.suggest', $latest)), {
                                  method: 'POST',
                                  headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                              });
                              const data = await res.json();
                              if (res.ok) { this.message = data.reply; this.$nextTick(() => { this.grow(); this.$refs.message.focus(); }); }
                              else { this.error = data.message || 'The AI could not suggest a reply.'; }
                          } catch (e) {
                              this.error = 'The AI could not suggest a reply.';
                          } finally {
                              this.loading = false;
                          }
                      },
                  }"
                  x-init="$nextTick(() => grow())">
                @csrf
                @if (session('telegram_error'))
                    <p role="alert" class="mb-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-800">{{ session('telegram_error') }}</p>
                @endif
                <p x-show="error" x-text="error" x-cloak role="alert" class="mb-2 text-xs text-red-700"></p>
                <label for="reply-message" class="sr-only">Message the customer in Telegram</label>
                <div class="flex items-end gap-2">
                    <button type="button" @click="suggest()" :disabled="loading" title="Suggest reply" aria-label="Suggest reply"
                            class="shrink-0 h-10 inline-flex items-center gap-1.5 rounded-full px-3 text-xs font-medium text-slate-600 bg-white border border-slate-300 hover:bg-slate-100 disabled:opacity-50
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                        <svg class="w-4 h-4" :class="{ 'animate-pulse': loading }" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>
                        </svg>
                        <span class="hidden sm:inline" x-text="loading ? 'Thinking…' : 'Suggest reply'">Suggest reply</span>
                    </button>
                    <textarea id="reply-message" name="message" rows="1" maxlength="4000" required x-ref="message" x-model="message" @input="grow()"
                              @keydown.enter="if ($event.ctrlKey || $event.metaKey) { $event.preventDefault(); $el.form.requestSubmit(); }"
                              class="flex-1 min-w-0 resize-none bg-white border border-slate-300 rounded-2xl px-4 py-2.5 text-sm leading-5 focus:outline-none focus:ring-2 focus:ring-slate-400"
                              placeholder="Message to {{ $tgUser?->displayName() ?? 'the customer' }}">{{ old('message') }}</textarea>
                    <button type="submit" title="Send (Ctrl+Enter)"
                            class="shrink-0 w-10 h-10 rounded-full bg-slate-900 text-white flex items-center justify-center hover:bg-slate-700 transition-colors
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-slate-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                        <span class="sr-only">Send</span>
                    </button>
                </div>
                <p class="text-[11px] text-slate-500 mt-1.5">The customer sees it as a message from the bot. Check AI suggestions and replace any [TO FILL: …] before sending. Ctrl+Enter sends.</p>
            </form>
        @else
            <div class="shrink-0 bg-slate-50 border-t border-slate-200 px-4 py-3 text-center text-xs text-slate-500">
                {{ $recordedChat ? 'Recorded only — the bot does not reply in this chat.' : 'Website chat — answered by the AI assistant; staff cannot reply here.' }}
            </div>
        @endif
    </section>

    {{-- Details --}}
    <aside class="mt-4 lg:mt-0 lg:w-80 shrink-0 bg-white border border-slate-200 rounded-xl p-5 h-fit lg:max-h-full lg:overflow-y-auto">
        <dl class="space-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400">Started</dt>
                <dd class="text-slate-800">{{ $conversation->created_at->format('d.m.Y H:i') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Channel</dt>
                <dd class="text-slate-800">{{ $channelLabel }}</dd>
            </div>
            @if($conversation->external_id && $conversation->channel !== 'web')
                <div data-chat-id>
                    <dt class="text-xs text-slate-400">Chat ID</dt>
                    <dd class="text-slate-800 font-mono select-all break-all">{{ $conversation->external_id }}</dd>
                </div>
                <div data-from-ids>
                    <dt class="text-xs text-slate-400">From ID{{ count($fromIds) > 1 ? 's' : '' }}</dt>
                    @forelse($fromIds as $id => $name)
                        <dd class="text-slate-800"><span class="font-mono select-all break-all">{{ $id }}</span>@if($name) <span class="text-slate-500">· {{ $name }}</span>@endif</dd>
                    @empty
                        <dd class="text-slate-500">None recorded yet</dd>
                    @endforelse
                </div>
            @endif
            @if($conversation->title)
                <div>
                    <dt class="text-xs text-slate-400">{{ $conversation->channel === 'group' ? 'Group' : 'Chat with' }}</dt>
                    <dd class="text-slate-800">{{ $conversation->title }}</dd>
                </div>
            @endif
            @if($recordedChat)
                <div>
                    <dt class="text-xs text-slate-400">Recording</dt>
                    <dd class="text-slate-800">Recorded only — the bot does not reply in this chat.</dd>
                </div>
            @endif
            @if($tgUser)
                <div>
                    <dt class="text-xs text-slate-400">Telegram customer</dt>
                    <dd class="text-slate-800">
                        <a href="{{ route('admin.telegram-users.show', $tgUser) }}" class="font-medium underline">{{ $tgUser->displayName() ?? 'Not registered yet' }}</a>@if($tgUser->phone), {{ $tgUser->phone }}@endif
                        @if($tgUser->username)
                            <br><a href="https://t.me/{{ $tgUser->username }}" target="_blank" rel="noopener" class="underline">{{ '@'.$tgUser->username }}</a>
                        @endif
                    </dd>
                </div>
            @endif
            @unless($recordedChat)
                <div>
                    <dt class="text-xs text-slate-400">Language</dt>
                    <dd class="text-slate-800 uppercase">{{ $conversation->locale }}</dd>
                </div>
            @endunless
            @if($conversation->page)
                <div>
                    <dt class="text-xs text-slate-400">{{ $conversation->channel === 'web' ? 'Page' : 'Source' }}</dt>
                    <dd class="text-slate-800">{{ $conversation->page }}</dd>
                </div>
            @endif
            @if($recordedChat)
                <div>
                    <dt class="text-xs text-slate-400">Messages</dt>
                    <dd class="text-slate-800">{{ $conversation->message_count }}</dd>
                </div>
            @else
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
            @endif
        </dl>
    </aside>
</div>
@endsection
