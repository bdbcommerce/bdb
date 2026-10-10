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
</div>
@endsection
