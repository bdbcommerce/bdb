@extends('admin.layout')
@section('title', 'Telegram users')
@section('heading', 'Telegram users')

@section('content')
@php
    $tab = fn (bool $active) => 'text-xs font-medium rounded-md px-3 py-1.5 transition-colors whitespace-nowrap '
        .($active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500">Customers from the bot and the Premium account, created from their Telegram chat info. Open a customer to see and edit their details and every conversation.</p>
    <nav class="flex flex-wrap gap-1 bg-slate-100 rounded-lg p-1" aria-label="Filter users">
        @foreach($filters as $key => $label)
            <a href="{{ route('admin.telegram-users.index', array_filter(['filter' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}"
               @if($filter === $key) aria-current="true" @endif class="{{ $tab($filter === $key) }}">{{ $label }} <span class="text-slate-400">{{ $counts[$key] }}</span></a>
        @endforeach
    </nav>
</div>

<form method="GET" action="{{ route('admin.telegram-users.index') }}" class="flex gap-2 mb-4" role="search">
    @if($filter !== 'all')<input type="hidden" name="filter" value="{{ $filter }}">@endif
    <label for="user-search" class="sr-only">Search users</label>
    <input id="user-search" type="search" name="q" value="{{ $search }}" maxlength="100"
           placeholder="Name, phone, @username or Telegram ID"
           class="w-full max-w-md border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
    <button type="submit" class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50">Search</button>
    @if($search !== '')
        <a href="{{ route('admin.telegram-users.index', array_filter(['filter' => $filter === 'all' ? null : $filter])) }}" class="self-center text-xs text-slate-500 underline hover:text-slate-900">Clear</a>
    @endif
</form>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($users->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">{{ $search !== '' ? 'No users match your search' : 'No Telegram users yet' }}</p>
            <p class="text-xs text-slate-400 mt-1">Customers appear here as soon as they write to the bot.</p>
        </div>
    @else
        <div class="divide-y divide-slate-100">
            @foreach($users as $user)
                <a href="{{ route('admin.telegram-users.show', $user) }}" class="flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-slate-50">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full border border-slate-300 text-xs font-semibold text-slate-600 uppercase" aria-hidden="true">
                        {{ mb_substr($user->displayName() ?? '?', 0, 1) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-900 truncate">{{ $user->displayName() ?? 'No name yet' }}</p>
                        <p class="text-xs text-slate-500 truncate">
                            {{ $user->phone ?? 'No phone' }}
                            @if($user->company) · {{ $user->company }} @endif
                            @if($user->username) · {{ '@'.$user->username }} @endif
                            @if($user->locale) · <span class="uppercase">{{ $user->locale }}</span> @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0 ml-13 sm:ml-0">
                        <span class="text-[11px] font-medium rounded-full px-2 py-0.5 border border-slate-200 text-slate-500">{{ \App\Models\TelegramUser::SOURCES[$user->source] ?? 'Bot' }}</span>
                        @if($user->ask_registration && ! $user->isRegistered())
                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5 border border-slate-400 text-slate-700">Will be asked to register</span>
                        @endif
                        <span class="text-[11px] font-medium rounded-full px-2 py-0.5 {{ $user->isRegistered() ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-500' }}">
                            {{ $user->isRegistered() ? 'Registered' : (config('services.telegram.replies') ? 'Step: '.$user->step : 'Not registered') }}
                        </span>
                        <span class="text-xs text-slate-500">{{ $user->conversations_count }} {{ Str::plural('chat', $user->conversations_count) }}</span>
                        <span class="text-xs text-slate-400 w-24 text-right">
                            {{ $user->last_message_at ? \Illuminate\Support\Carbon::parse($user->last_message_at)->diffForHumans() : $user->created_at->diffForHumans() }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
