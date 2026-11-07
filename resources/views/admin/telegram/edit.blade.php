@extends('admin.layout')
@section('title', 'Telegram bot')
@section('heading', 'Telegram bot')

@php
    $secondaryBtn = 'inline-flex items-center justify-center gap-2 border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400';
@endphp

@section('content')
<div class="w-full space-y-6">
    @if (session('telegram_error'))
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            {{ session('telegram_error') }}
        </div>
    @endif

    {{-- Configuration (read-only, from .env) --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <div>
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Configuration</h2>
            <p class="text-xs text-slate-400 mt-1">Set these in <code class="bg-slate-100 px-1 rounded">.env</code> on the server, then run <code class="bg-slate-100 px-1 rounded">php artisan config:clear</code>.</p>
        </div>
        <dl class="divide-y divide-slate-100 text-sm">
            @foreach ([
                'TELEGRAM_BOT_TOKEN' => 'Bot token from @BotFather',
                'TELEGRAM_CHAT_ID' => 'Sales group that receives new leads',
                'TELEGRAM_WEBHOOK_SECRET' => 'Webhook secret, 16+ letters, digits, _ or -',
            ] as $var => $hint)
                <div class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <dt>
                        <code class="font-mono text-slate-900">{{ $var }}</code>
                        <span class="block text-xs text-slate-400">{{ $hint }}</span>
                    </dt>
                    <dd class="text-[11px] font-medium rounded-full px-2 py-0.5 {{ $env[$var] ? 'bg-slate-900 text-white' : 'bg-white text-slate-500 border border-slate-300' }}">
                        {{ $env[$var] ? 'Set' : 'Not set' }}
                    </dd>
                </div>
            @endforeach
        </dl>
        <p class="text-xs text-slate-500">
            Customers: {{ $users['registered'] }} registered, {{ $users['pending'] }} started but not finished registration.
        </p>
    </div>

    {{-- Connection --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <div>
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Connection</h2>
            <p class="text-xs text-slate-400 mt-1">
                Webhook URL: <code class="bg-slate-100 px-1 rounded break-all">{{ $webhookUrl }}</code>.
                Telegram needs this site on a public HTTPS address.
            </p>
        </div>

        @if ($status === false)
            <p role="alert" class="text-sm text-red-700">Could not reach Telegram with this token. Check TELEGRAM_BOT_TOKEN and see storage/logs/laravel.log.</p>
        @elseif ($status)
            <dl class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-slate-500">Bot</dt>
                <dd class="text-slate-900 font-medium">{{ $status['bot'] }}</dd>
                <dt class="text-slate-500">Webhook</dt>
                <dd class="text-slate-900 break-all">
                    @if ($status['connected'])
                        Connected to this site
                    @elseif ($status['url'])
                        Points elsewhere: {{ $status['url'] }}
                    @else
                        Not connected
                    @endif
                </dd>
                <dt class="text-slate-500">Update types</dt>
                <dd class="text-slate-900">
                    @if ($status['missing_updates'])
                        <span class="text-red-700">Missing {{ implode(', ', $status['missing_updates']) }} — press Connect webhook again.</span>
                    @else
                        All needed types
                    @endif
                </dd>
                <dt class="text-slate-500">Business mode</dt>
                <dd class="text-slate-900">{{ $status['business_mode'] ? 'On' : 'Off — turn it on in @BotFather (Bot Settings → Business Mode)' }}</dd>
                <dt class="text-slate-500">Group messages</dt>
                <dd class="text-slate-900">{{ $status['groups_visible'] ? 'Bot sees all messages' : 'Only commands and replies to the bot — disable privacy mode in @BotFather (/setprivacy), or make the bot a group admin' }}</dd>
                <dt class="text-slate-500">Pending updates</dt>
                <dd class="text-slate-900">{{ $status['pending'] }}</dd>
                <dt class="text-slate-500">Last error</dt>
                <dd class="text-slate-900 break-words">{{ $status['last_error'] ?? '—' }}</dd>
            </dl>
        @endif

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.telegram.edit', ['check' => 1]) }}" class="{{ $secondaryBtn }}">Check status</a>
            <form method="POST" action="{{ route('admin.telegram.connect') }}">
                @csrf
                <button type="submit" class="{{ $secondaryBtn }}">Connect webhook</button>
            </form>
            <form method="POST" action="{{ route('admin.telegram.test') }}">
                @csrf
                <button type="submit" class="{{ $secondaryBtn }}">Send test to sales chat</button>
            </form>
            <form method="POST" action="{{ route('admin.telegram.disconnect') }}"
                  onsubmit="return confirm('Disconnect the webhook? The bot will stop answering customers.')">
                @csrf
                <button type="submit" class="{{ $secondaryBtn }}">Disconnect</button>
            </form>
        </div>
    </div>

    {{-- Telegram Business (Premium account) --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <div>
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Premium account (Telegram Business)</h2>
            <p class="text-xs text-slate-400 mt-1">The bot records the private chats of a connected Premium account. It does not reply in them.</p>
        </div>
        <ol class="list-decimal pl-5 space-y-1 text-sm text-slate-700">
            <li>In <span class="font-medium">@BotFather</span>: your bot → Bot Settings → <span class="font-medium">Business Mode</span> → Turn on.</li>
            <li>On this page press <span class="font-medium">Connect webhook</span> once more, so Telegram sends Business updates.</li>
            <li>In the Premium account: Settings → <span class="font-medium">Telegram Business</span> → <span class="font-medium">Chatbots</span> → enter the bot's @username and choose which chats it can access.</li>
        </ol>
        @if ($connections->isEmpty())
            <p class="text-sm text-slate-500">No account connected yet.</p>
        @else
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($connections as $connection)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <span>
                            <span class="font-medium text-slate-900">{{ $connection->label() }}</span>
                            @if ($connection->username && $connection->name)
                                <span class="text-slate-500">{{ '@'.$connection->username }}</span>
                            @endif
                            <span class="block text-xs text-slate-400">Since {{ $connection->created_at->format('d.m.Y') }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5 {{ $connection->is_enabled ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-500' }}">{{ $connection->is_enabled ? 'Recording' : 'Disconnected' }}</span>
                            <a href="{{ route('admin.chat-conversations.index', ['channel' => 'business']) }}" class="text-xs font-medium text-slate-600 underline hover:text-slate-900">Chats</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Groups --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <div>
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Groups</h2>
            <p class="text-xs text-slate-400 mt-1">Messages in groups the bot belongs to are recorded, without replies. The sales and alert chats from .env are not recorded.</p>
        </div>
        <ol class="list-decimal pl-5 space-y-1 text-sm text-slate-700">
            <li>In <span class="font-medium">@BotFather</span>: <code class="bg-slate-100 px-1 rounded">/setprivacy</code> → your bot → <span class="font-medium">Disable</span> (otherwise the bot only sees commands and replies to it).</li>
            <li>Add the bot to the group. If it was already there, remove and add it again — the privacy change applies only then. Making the bot a group admin also works.</li>
        </ol>
        @if ($groups->isEmpty())
            <p class="text-sm text-slate-500">No group messages recorded yet.</p>
        @else
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($groups as $group)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                        <span class="font-medium text-slate-900">{{ $group->title ?? $group->external_id }}</span>
                        <span class="text-xs text-slate-500">{{ $group->messages }} {{ Str::plural('message', (int) $group->messages) }}</span>
                    </li>
                @endforeach
            </ul>
            <a href="{{ route('admin.chat-conversations.index', ['channel' => 'group']) }}" class="inline-block text-xs font-medium text-slate-600 underline hover:text-slate-900">Open group chats</a>
        @endif
    </div>
</div>
@endsection
