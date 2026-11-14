@extends('admin.layout')
@section('title', 'Invoice parser settings')
@section('heading', 'Invoice parser — Settings')

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $envKey = filled(config('services.anthropic.key'));
@endphp

@section('content')
<div class="w-full space-y-6">
    <div class="bg-white border border-slate-200 rounded-xl p-6 text-sm text-slate-600 space-y-2">
        <p><b class="text-slate-900">How it works.</b> The bot watches the <b>source chat</b>. Every photo (or image / PDF file) posted there is sent to Claude, which checks whether it is a printed invoice and reads it. For an invoice, a summary in Uzbek is posted to the <b>destination chat</b>. Handwritten notes, receipts and other photos are skipped. Every photo is listed under <a href="{{ route('admin.invoice-messages.index') }}" class="underline">Messages</a>.</p>
        <p>Each photo is read right after it arrives (usually 10–40 seconds). If Claude is busy or a step fails, it is retried every minute by the scheduler (up to {{ \App\Models\InvoiceMessage::MAX_ATTEMPTS }} attempts) — the server needs the <code class="text-xs">schedule:run</code> cron.</p>
        <p><b class="text-slate-900">The bot must see the photos:</b> add it to the source chat; in a group, either make it an admin or turn off its privacy mode (BotFather → /setprivacy → Disable, then remove and re-add the bot). Add it to the destination chat too, with permission to post.</p>
        @unless($telegramReady)
            <p class="text-red-600">The Telegram bot is not configured (TELEGRAM_BOT_TOKEN in .env).</p>
        @endunless
    </div>

    <form method="POST" action="{{ route('admin.invoice-parser.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Claude API</h2>
            <div>
                <label for="api_key" class="block text-sm font-medium text-slate-700 mb-1">API key</label>
                <input type="password" id="api_key" name="api_key" autocomplete="new-password" placeholder="{{ $settings->hasStoredKey() ? 'Saved'.($settings->keyHint() ? ' ('.$settings->keyHint().')' : ' (cannot be decrypted — enter it again)').' — leave empty to keep it' : 'sk-ant-…' }}" class="{{ $input }}">
                <p class="text-xs text-slate-400 mt-1">
                    From console.anthropic.com → API keys. Stored encrypted.
                    @if(! $settings->hasStoredKey() && $envKey) Not set here, so <b>ANTHROPIC_API_KEY from .env</b> is used ({{ $settings->keyHint() }}).@endif
                    @if(! $settings->hasStoredKey() && ! $envKey) You can also put it in .env as ANTHROPIC_API_KEY instead.@endif
                </p>
                @if($settings->hasStoredKey())
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="remove_key" value="1" class="rounded border-slate-300"> Remove the saved key{{ $envKey ? ' (then the .env key is used)' : '' }}
                    </label>
                @endif
            </div>
            <div>
                <label for="daily_limit" class="block text-sm font-medium text-slate-700 mb-1">Daily limit — paid Claude reads per day</label>
                <input type="number" id="daily_limit" name="daily_limit" min="1" max="5000" value="{{ old('daily_limit', $settings->dailyLimit()) }}" class="{{ $input }} max-w-40">
                <p class="text-xs text-slate-400 mt-1">Protects the bill if the source chat is flooded with photos. Photos over the limit are listed as failed; “Read again” reads one anyway. Also set a monthly spend limit in the Anthropic console.</p>
            </div>
            <div>
                <label for="model" class="block text-sm font-medium text-slate-700 mb-1">Model</label>
                <select id="model" name="model" class="{{ $input }} bg-white">
                    @foreach($models as $id => $label)
                        <option value="{{ $id }}" @selected(old('model', $settings->modelId()) === $id)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Telegram chats</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="source_chat_id" class="block text-sm font-medium text-slate-700 mb-1">Source chat ID — the bot reads photos here</label>
                    <input type="text" id="source_chat_id" name="source_chat_id" inputmode="numeric" value="{{ old('source_chat_id', $settings->source_chat_id) }}" placeholder="-1001234567890" class="{{ $input }} font-mono">
                </div>
                <div>
                    <label for="target_chat_id" class="block text-sm font-medium text-slate-700 mb-1">Destination chat ID — summaries are posted here</label>
                    <input type="text" id="target_chat_id" name="target_chat_id" inputmode="numeric" value="{{ old('target_chat_id', $settings->target_chat_id) }}" placeholder="-1009876543210" class="{{ $input }} font-mono">
                </div>
            </div>
            <p class="text-xs text-slate-400">Group IDs start with -100. To find one, add the bot to the group, post a message, and see the chat under AI Assistant → Conversations, or use a bot such as @getidsbot. Messages in the source chat are only read for invoices — they are not recorded as conversations or answered, so use a dedicated group (not a channel, and not your own private chat with the bot).</p>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="notify_skipped" value="1" @checked(old('notify_skipped', $settings->notify_skipped)) class="rounded border-slate-300">
                Also tell the destination chat when a photo is not an invoice
            </label>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-3" x-data="{ prompt: @js(old('prompt', $settings->editablePrompt())), original: @js($defaultPrompt) }">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Instructions for Claude</h2>
                <button type="button" x-show="prompt.trim() !== original.trim()" @click="prompt = original" class="text-xs text-slate-500 hover:text-slate-900 underline">Reset to the built-in text</button>
            </div>
            <textarea name="prompt" aria-label="Instructions for Claude" rows="18" x-model="prompt" class="{{ $input }} font-mono text-xs leading-relaxed"></textarea>
            <p class="text-xs text-slate-400">Claude must reply with the JSON described here (status "accepted" or "skipped" and the invoice fields): the summary is built from those field names. Change the wording freely, but keep the JSON format.</p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled)) class="rounded border-slate-300">
                Invoice parser is on
            </label>
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save settings</button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.invoice-parser.test') }}" class="bg-white border border-slate-200 rounded-xl p-6 flex flex-wrap items-center justify-between gap-3">
        @csrf
        <p class="text-sm text-slate-600">Check the saved key and model with one tiny request (costs a fraction of a cent).</p>
        <button type="submit" class="text-sm font-medium rounded-lg px-4 py-2 border border-slate-300 hover:border-slate-500">Test connection</button>
    </form>
</div>
@endsection
