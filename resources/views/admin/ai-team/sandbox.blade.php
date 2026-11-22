@extends('admin.layout')
@section('title', 'Sandbox')
@section('heading', 'AI Sales & Support Team — Sandbox')

@php use App\Models\AiTrace; @endphp

@section('content')
<div class="w-full space-y-6">
    <p class="text-sm text-slate-600 max-w-3xl">
        Play the customer and see what an AI employee answers — also with a draft prompt — before real customers see it.
        Each message makes a <span class="font-medium text-slate-800">real Claude call</span> (it is billed), but nothing reaches Telegram:
        no message, no request to the sales chat, no customer card. Below the chat: the path of each turn and exactly what was sent at every point.
    </p>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="bg-white border border-slate-200 rounded-xl p-4 space-y-3 text-sm" aria-labelledby="setup-h">
            <h2 id="setup-h" class="font-semibold text-slate-900">New test chat</h2>
            <form method="POST" action="{{ route('admin.ai-team.sandbox.start') }}" class="space-y-3">
                @csrf
                <label class="block"><span class="text-slate-600">AI employee</span>
                    <select name="ai_employee_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        @foreach ($employees as $e)
                            <option value="{{ $e->id }}" @selected($chat?->ai_employee_id === $e->id)>{{ $e->name }} — {{ $e->role }}{{ $e->isLive() ? ' (live)' : '' }}</option>
                        @endforeach
                    </select></label>
                <label class="block"><span class="text-slate-600">Prompt</span>
                    <select name="prompt" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="published" @selected($chat?->prompt !== 'draft')>Published version</option>
                        <option value="draft" @selected($chat?->prompt === 'draft')>Draft (published when there is none)</option>
                    </select></label>
                <label class="block"><span class="text-slate-600">Customer writes to</span>
                    <select name="via" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="bot" @selected($chat?->via !== 'assistant')>The bot</option>
                        <option value="assistant" @selected($chat?->via === 'assistant')>The Premium account</option>
                    </select></label>
                <button class="w-full bg-slate-900 text-white font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Start a new chat</button>
            </form>
            @if ($chats->count() > 1)
                <div>
                    <p class="text-xs font-medium text-slate-900 mb-1">Your recent test chats</p>
                    <ul class="space-y-1">
                        @foreach ($chats as $c)
                            <li><a href="{{ route('admin.ai-team.sandbox', ['chat' => $c->id]) }}" @if($chat?->is($c)) aria-current="page" @endif class="text-xs {{ $chat?->is($c) ? 'font-semibold text-slate-900' : 'text-slate-600 underline' }}">#{{ $c->id }} · {{ $c->employee?->name ?? '—' }} · {{ count($c->messages ?? []) }} messages · {{ $c->updated_at->timezone(config('app.timezone'))->format('d.m H:i') }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

        <section class="lg:col-span-2 bg-white border border-slate-200 rounded-xl overflow-hidden flex flex-col" aria-label="Test conversation">
            @if ($chat)
                <div class="px-4 py-2.5 border-b border-slate-200 bg-slate-50 text-sm text-slate-700 flex flex-wrap items-center justify-between gap-2">
                    <span>#{{ $chat->id }} · {{ $chat->employee?->name ?? 'deleted employee' }} · {{ $chat->prompt === 'draft' ? 'draft prompt' : 'published prompt' }} · {{ $chat->via === 'assistant' ? 'Premium-account chat' : 'bot chat' }}</span>
                    @if (collect($chat->messages ?? [])->where('role', 'user')->isNotEmpty())
                        <a href="{{ route('admin.ai-test-cases.create', ['sandbox' => $chat->id]) }}" class="text-xs underline text-slate-600 hover:text-slate-900">Save as test case</a>
                    @endif
                </div>
                <div class="flex-1 bg-[#efeae2] p-4 min-h-[18rem] max-h-[32rem] overflow-y-auto">
                    @if ($chat->messages)
                        @include('admin.chat-conversations._messages', ['messages' => $chat->messages])
                    @else
                        <p class="text-sm text-slate-600 text-center mt-16">Write the first message as the customer.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.ai-team.sandbox.message', $chat) }}" class="border-t border-slate-200 bg-slate-50 px-3 py-2.5 flex items-end gap-2"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <label for="sandbox-message" class="sr-only">Message as the customer</label>
                    <textarea id="sandbox-message" name="message" rows="1" maxlength="2000" required
                              class="flex-1 min-w-0 resize-none rounded-2xl border-slate-300 bg-white px-4 py-2.5 text-sm" placeholder="Write as the customer…"
                              @keydown.enter="if ($event.ctrlKey || $event.metaKey) { $event.preventDefault(); $el.form.requestSubmit(); }"></textarea>
                    <button class="shrink-0 rounded-full bg-slate-900 text-white px-4 h-10 text-sm font-medium hover:bg-slate-700 disabled:opacity-50" :disabled="busy">
                        <span x-show="! busy">Send</span><span x-show="busy" x-cloak>Claude is thinking…</span>
                    </button>
                </form>
            @else
                <p class="p-6 text-sm text-slate-600">Start a test chat on the left.</p>
            @endif
        </section>
    </div>

    @if ($trace)
        <section class="bg-white border border-slate-200 rounded-xl p-5 space-y-4" aria-labelledby="trace-h">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="trace-h" class="text-sm font-semibold text-slate-900">Trace of “{{ \Illuminate\Support\Str::limit($trace->customer_text ?? '', 80) }}”</h2>
                @if ($traces->count() > 1)
                    <nav class="flex flex-wrap gap-1.5" aria-label="Turns of this chat">
                        @foreach ($traces as $i => $t)
                            <a href="{{ route('admin.ai-team.sandbox', ['chat' => $chat->id, 'trace' => $t->id]) }}" @if($t->is($trace)) aria-current="true" @endif
                               class="text-xs rounded-md border px-2 py-0.5 {{ $t->is($trace) ? 'bg-slate-900 text-white border-slate-900' : 'border-slate-300 text-slate-600' }}">Turn {{ $i + 1 }}</a>
                        @endforeach
                    </nav>
                @endif
            </div>
            @include('admin.ai-team._trace', ['trace' => $trace])
        </section>
    @endif
</div>
@endsection
