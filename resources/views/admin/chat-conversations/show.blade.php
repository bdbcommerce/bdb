@extends('admin.layout')
@section('title', 'Conversation')
@section('heading', 'Conversation')

@section('content')
@php $recordedChat = in_array($conversation->channel, ['business', 'group'], true); @endphp
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <a href="{{ route('admin.chat-conversations.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; All conversations</a>
    <form method="POST" action="{{ route('admin.chat-conversations.destroy', $conversation) }}" onsubmit="return confirm('Delete this conversation?')">
        @csrf @method('DELETE')
        <button class="text-xs text-slate-400 hover:text-red-600">Delete</button>
    </form>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-5 space-y-3">
        @include('admin.chat-conversations._messages', ['messages' => $conversation->messages])

        @if($conversation->channel === 'telegram' && $conversation->external_id)
            @if (session('telegram_error'))
                <p role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('telegram_error') }}</p>
            @endif
            <form method="POST" action="{{ route('admin.chat-conversations.reply', $conversation) }}"
                  class="border-t border-slate-200 pt-4 mt-2 space-y-2"
                  x-data="{
                      message: @js(old('message', '')),
                      loading: false,
                      error: '',
                      async suggest() {
                          this.loading = true; this.error = '';
                          try {
                              const res = await fetch(@js(route('admin.chat-conversations.suggest', $conversation)), {
                                  method: 'POST',
                                  headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                              });
                              const data = await res.json();
                              if (res.ok) { this.message = data.reply; this.$nextTick(() => this.$refs.message.focus()); }
                              else { this.error = data.message || 'The AI could not suggest a reply.'; }
                          } catch (e) {
                              this.error = 'The AI could not suggest a reply.';
                          } finally {
                              this.loading = false;
                          }
                      },
                  }">
                @csrf
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label for="reply-message" class="block text-sm font-medium text-slate-700">Message the customer in Telegram</label>
                    <button type="button" @click="suggest()" :disabled="loading"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-50 disabled:opacity-50
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                        <svg class="w-4 h-4" :class="{ 'animate-pulse': loading }" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>
                        </svg>
                        <span x-text="loading ? 'Thinking…' : 'Suggest reply'">Suggest reply</span>
                    </button>
                </div>
                <textarea id="reply-message" name="message" rows="3" maxlength="4000" required x-ref="message" x-model="message"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                          placeholder="Sent from the bot to {{ $conversation->telegramUser?->name ?? 'the customer' }}">{{ old('message') }}</textarea>
                <p x-show="error" x-text="error" x-cloak role="alert" class="text-xs text-red-700"></p>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-slate-400">The customer sees it as a message from the bot. Check AI suggestions and replace any [TO FILL: …] before sending.</p>
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
                <dd class="text-slate-800">{{ \App\Models\ChatConversation::CHANNELS[$conversation->channel] ?? ucfirst($conversation->channel) }}</dd>
            </div>
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
            @if($conversation->channel === 'telegram' && $conversation->telegramUser)
                @php $tgUser = $conversation->telegramUser; @endphp
                <div>
                    <dt class="text-xs text-slate-400">Telegram customer</dt>
                    <dd class="text-slate-800">
                        <a href="{{ route('admin.telegram-users.show', $tgUser) }}" class="font-medium underline">{{ $tgUser->name ?? 'Not registered yet' }}</a>@if($tgUser->phone), {{ $tgUser->phone }}@endif
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
