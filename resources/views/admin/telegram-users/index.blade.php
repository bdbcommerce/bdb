@extends('admin.layout')
@section('title', 'Contacts')
@section('heading', 'Contacts')

@section('content')
@php
    $tab = fn (bool $active) => 'text-xs font-medium rounded-md px-3 py-1.5 transition-colors whitespace-nowrap '
        .($active ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900');
@endphp

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500 max-w-2xl">Everyone who writes to the bot or the Premium account. Set who each contact is right here — client, supplier or employee (with their role, and whether they answer the AI’s questions) — and the company they work at. Open a contact for their details and every conversation.</p>
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
        @php $field = 'border border-slate-300 rounded-md px-2 py-1 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-slate-400'; @endphp
        <datalist id="company-list">@foreach($companies as $company)<option value="{{ $company }}">@endforeach</datalist>
        <div class="divide-y divide-slate-100">
            @foreach($users as $user)
                <div class="hover:bg-slate-50">
                <a href="{{ route('admin.telegram-users.show', $user) }}" class="flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-1 px-4 pt-3 pb-1.5">
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
                            {{ $user->isRegistered() ? 'Registered' : ($user->step === \App\Models\TelegramUser::STEP_GUEST ? 'Not registered (chatting)' : (config('services.telegram.replies') ? 'Step: '.$user->step : 'Not registered')) }}
                        </span>
                        <span class="text-xs text-slate-500">{{ $user->conversations_count }} {{ Str::plural('chat', $user->conversations_count) }}</span>
                        <span class="text-xs text-slate-400 w-24 text-right">
                            {{ $user->last_message_at ? \Illuminate\Support\Carbon::parse($user->last_message_at)->diffForHumans() : $user->created_at->diffForHumans() }}
                        </span>
                    </div>
                </a>
                {{-- Quick assignment: who the contact is and where they work. --}}
                <form method="POST" action="{{ route('admin.telegram-users.assign', $user) }}" x-data="{ type: @js((string) $user->contact_type), changed: false }" @input="changed = true"
                      class="flex flex-wrap items-center gap-2 pl-[4.25rem] pr-4 pb-3" aria-label="Assign {{ $user->displayName() ?? 'contact' }}">
                    @csrf @method('PUT')
                    <label class="sr-only" for="type-{{ $user->id }}">Type</label>
                    <select id="type-{{ $user->id }}" name="contact_type" x-model="type" class="{{ $field }} {{ $user->contact_type ? '' : 'border-slate-900' }}">
                        <option value="">Not assigned</option>
                        @foreach(\App\Models\TelegramUser::TYPES as $value => $label)
                            <option value="{{ $value }}" @selected($user->contact_type === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span x-show="type === 'employee'" x-cloak class="contents">
                        <label class="sr-only" for="role-{{ $user->id }}">Role</label>
                        <select id="role-{{ $user->id }}" name="employee_role" class="{{ $field }}">
                            <option value="">Role…</option>
                            @foreach($roles as $role)
                                <option value="{{ $role }}" @selected($user->employee_role === $role)>{{ $role }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-1 text-xs text-slate-600">
                            <input type="checkbox" name="answers_questions" value="1" class="rounded border-slate-300" @checked($user->answers_questions)> Answers AI questions
                        </label>
                        @if($user->is_main_admin)<span class="text-[11px] font-medium rounded-full px-2 py-0.5 bg-slate-900 text-white">Main admin</span>@endif
                    </span>
                    <label class="sr-only" for="company-{{ $user->id }}">Company</label>
                    <input id="company-{{ $user->id }}" name="company" value="{{ $user->company }}" maxlength="120" list="company-list" placeholder="Company" class="{{ $field }} w-44">
                    <button type="submit" x-show="changed" x-cloak class="text-xs font-medium rounded-md px-3 py-1 bg-slate-900 text-white hover:bg-slate-700">Save</button>
                </form>
                </div>
            @endforeach
        </div>
    @endif
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
