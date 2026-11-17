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
        <p><b class="text-slate-900">How it works.</b> The bot watches the <b>source chat</b>. Every photo (or image / PDF file) posted there is sent to Claude, which checks whether it is a printed invoice and reads it. For an invoice, a summary in Uzbek is posted to the <b>invoice chat</b> and the read data as JSON to the <b>JSON chat</b>. With <b>From IDs</b> set, only those senders' photos are read. Handwritten notes, receipts and other photos are skipped. Every photo is listed under <a href="{{ route('admin.invoice-messages.index') }}" class="underline">Messages</a>.</p>
        <p>Photos are saved as they arrive and read by the scheduler every minute, one at a time — a photo is usually read within 1–2 minutes (Claude itself takes 10–40 seconds), and many photos at once take a few minutes. If Claude is busy or a step fails, it is retried (up to {{ \App\Models\InvoiceMessage::MAX_ATTEMPTS }} attempts). The server needs the <code class="text-xs">schedule:run</code> cron, or photos stay “Waiting”. “Read again” on a message reads it at once.</p>
        <p><b class="text-slate-900">The bot must see the photos:</b> add it to the source chat; in a group, either make it an admin or turn off its privacy mode (BotFather → /setprivacy → Disable, then remove and re-add the bot). Add it to the invoice chat and the JSON chat too, with permission to post.</p>
        @unless(\App\Services\Invoices\InvoiceParser::schedulerRunning())
            <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                The scheduler has not run in the last few minutes, so the bot reads each new photo itself as it arrives. Retries of failed photos need the scheduler: add this cron line on the server —
                <code class="text-xs break-all">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
            </p>
        @endunless
        @unless($telegramReady)
            <p class="text-red-600">The Telegram bot is not configured (TELEGRAM_BOT_TOKEN in .env).</p>
        @endunless
    </div>

    <form method="POST" action="{{ route('admin.invoice-parser.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Claude API</h2>
            <div>
                <p class="block text-sm font-medium text-slate-700 mb-1">API key</p>
                @if($envKey)
                    <p class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">✓ Set in .env (ANTHROPIC_API_KEY {{ '…'.substr((string) config('services.anthropic.key'), -4) }})</p>
                @elseif($settings->hasStoredKey())
                    <p class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Not in .env — using a key saved here earlier{{ $settings->keyHint() ? ' ('.$settings->keyHint().')' : ' (cannot be decrypted)' }}</p>
                @else
                    <p class="inline-flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">Not set — add ANTHROPIC_API_KEY to .env</p>
                @endif
                <p class="text-xs text-slate-400 mt-1">From console.anthropic.com → API keys. Put it in the server's .env as <code>ANTHROPIC_API_KEY=…</code>, then run <code>php artisan config:cache</code>.</p>
                @if($settings->hasStoredKey())
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="remove_key" value="1" class="rounded border-slate-300"> Remove the key saved here earlier{{ $envKey ? ' (the .env key is used anyway)' : '' }}
                    </label>
                @endif
                @error('api_key')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="daily_limit" class="block text-sm font-medium text-slate-700 mb-1">Daily limit — paid Claude reads per day (optional)</label>
                <input type="number" id="daily_limit" name="daily_limit" min="1" max="5000" value="{{ old('daily_limit', $settings->dailyLimit()) }}" placeholder="No limit" class="{{ $input }} max-w-40">
                <p class="text-xs text-slate-400 mt-1">Empty = no limit. Each read is paid to Anthropic by tokens — see the cost on <a href="{{ route('admin.invoice-messages.index') }}" class="underline">Messages</a>. A limit protects the bill if the source chat is flooded with photos: photos over it are listed as failed, and “Read again” reads one anyway.</p>
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
                    @error('source_chat_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="source_from_ids" class="block text-sm font-medium text-slate-700 mb-1">From IDs — read photos only from these senders</label>
                    <input type="text" id="source_from_ids" name="source_from_ids" value="{{ old('source_from_ids', $settings->source_from_ids) }}" placeholder="Empty = anyone in the source chat" class="{{ $input }} font-mono">
                    <p class="text-xs text-slate-400 mt-1">Telegram user IDs, separated by commas. Shown as “From ID” under Messages and AI Assistant → Conversations.</p>
                    @error('source_from_ids')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="target_chat_id" class="block text-sm font-medium text-slate-700 mb-1">Invoice chat ID — the summary is posted here</label>
                    <input type="text" id="target_chat_id" name="target_chat_id" inputmode="numeric" value="{{ old('target_chat_id', $settings->target_chat_id) }}" placeholder="-1009876543210" class="{{ $input }} font-mono">
                    @error('target_chat_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="json_chat_id" class="block text-sm font-medium text-slate-700 mb-1">JSON chat ID — the read data is posted here as JSON</label>
                    <input type="text" id="json_chat_id" name="json_chat_id" inputmode="numeric" value="{{ old('json_chat_id', $settings->json_chat_id) }}" placeholder="Empty = no JSON" class="{{ $input }} font-mono">
                    <p class="text-xs text-slate-400 mt-1">Code block; a long invoice comes as a .json file. Can be the same chat as the invoice chat.</p>
                    @error('json_chat_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="image_chat_id" class="block text-sm font-medium text-slate-700 mb-1">Image chat ID — the original photo is posted here</label>
                    <input type="text" id="image_chat_id" name="image_chat_id" inputmode="numeric" value="{{ old('image_chat_id', $settings->image_chat_id) }}" placeholder="Empty = no photo" class="{{ $input }} font-mono">
                    <p class="text-xs text-slate-400 mt-1">Each accepted invoice's photo (or PDF), with its number in the caption. Claude says when a photo is sideways or upside down, and it is turned upright before posting. Can be the same chat as the invoice chat: the summary is then posted as a reply to the photo.</p>
                    @error('image_chat_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
            <p class="text-xs text-slate-400">Set the invoice chat, the JSON chat, or both (the image chat is extra).</p>
            <p class="text-xs text-slate-400">Group IDs start with -100. To find one, add the bot to the group, post a message, and see the chat under AI Assistant → Conversations, or use a bot such as @getidsbot. Messages in the source chat are only read for invoices — they are not recorded as conversations or answered, so use a dedicated group (not a channel, and not your own private chat with the bot).</p>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="notify_skipped" value="1" @checked(old('notify_skipped', $settings->notify_skipped)) class="rounded border-slate-300">
                Also tell the invoice / JSON chat when a photo is not an invoice
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
            <div>
                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled)) class="rounded border-slate-300">
                    Invoice parser is on
                </label>
                @error('enabled')<p class="text-xs text-amber-700 mt-1">{{ $message }}</p>@enderror
            </div>
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
