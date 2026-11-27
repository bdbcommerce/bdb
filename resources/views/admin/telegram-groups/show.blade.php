@extends('admin.layout')
@section('title', $group->title ?: 'Telegram group')
@section('heading', $group->title ?: 'Telegram group')

@section('content')
@php
    $card = 'bg-white border border-slate-200 rounded-xl';
    $btn = 'border border-slate-300 bg-white text-slate-700 text-xs font-medium rounded-lg px-3 py-1.5 hover:bg-slate-50';
    $dark = 'bg-slate-900 text-white text-xs font-medium rounded-lg px-3 py-1.5 hover:bg-slate-700';
    $status = ['member' => 'Member', 'administrator' => 'Admin', 'creator' => 'Owner', 'restricted' => 'Restricted', 'left' => 'Removed / left', 'kicked' => 'Removed (banned)'];
@endphp

<a href="{{ route('admin.telegram-groups.index') }}" class="inline-block text-xs text-slate-500 hover:text-slate-900 mb-4">← All groups</a>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        {{-- What the group is used for. --}}
        <section class="{{ $card }} p-5">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Used for</h2>
            @if($roles)
                <ul class="space-y-1.5 mb-4">
                    @foreach($roles as $role)
                        <li class="text-sm text-slate-700"><span class="font-medium text-slate-900">{{ \App\Models\TelegramChat::ROLE_NAMES[$role] }}</span> — {{ \Illuminate\Support\Str::after(\App\Models\TelegramChat::ROLES[$role], '— ') }}</li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-slate-500 mb-4">Nothing special — an ordinary group{{ $group->is_recorded ? ' whose messages are recorded' : '' }}.</p>
            @endif
            <div class="flex flex-wrap gap-2">
                @foreach(\App\Models\TelegramChat::SETTABLE_ROLES as $role => $field)
                    @php $has = in_array($role, $roles, true); @endphp
                    <form method="POST" action="{{ route('admin.telegram-groups.role', $group) }}"
                          @if($role === 'invoice_source' && ! $has) onsubmit="return confirm('The bot will read invoice photos here, and the group\'s messages will no longer be recorded as conversations. Continue?')" @endif>
                        @csrf @method('PUT')
                        <input type="hidden" name="role" value="{{ $role }}">
                        <input type="hidden" name="on" value="{{ $has ? 0 : 1 }}">
                        <button class="{{ $btn }}">{{ $has ? 'Stop using as '.\App\Models\TelegramChat::ROLE_NAMES[$role] : 'Use as '.\App\Models\TelegramChat::ROLE_NAMES[$role] }}</button>
                    </form>
                @endforeach
            </div>
            <p class="text-xs text-slate-400 mt-3">The sales and alerts chats are set in .env (TELEGRAM_CHAT_ID / TELEGRAM_ALERT_CHAT_ID). All invoice settings: <a href="{{ route('admin.invoice-parser.edit') }}" class="underline hover:text-slate-700">Invoice parser → Settings</a>.</p>
        </section>

        {{-- Companies. --}}
        <section class="{{ $card }}">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide px-5 pt-5 pb-3">Companies</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse($links as $link)
                    <li class="flex flex-wrap items-center gap-2 px-5 py-2.5">
                        <a href="{{ route('admin.clients.show', $link->client) }}" class="font-medium text-slate-900 hover:underline">{{ $link->client?->name }}</a>
                        @if($link->client?->inn)<span class="text-xs text-slate-500 font-mono">{{ $link->client->inn }}</span>@endif
                        <span class="text-xs text-slate-400">{{ $link->linked_by === 'manager' ? 'linked by '.($link->linked_by_name ?: 'a manager') : 'found by the AI (INN written in the group)' }}</span>
                        <form method="POST" action="{{ route('admin.companies.unlink', $link) }}" class="ml-auto" onsubmit="return confirm('Unlink this company from the group?')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-slate-500 underline hover:text-slate-900">Unlink</button>
                        </form>
                    </li>
                @empty
                    <li class="px-5 py-3 text-slate-500">No company yet. When someone writes a company's INN in the group, it is linked automatically.</li>
                @endforelse
            </ul>
            @if($companies->isNotEmpty())
                <form method="POST" action="{{ route('admin.telegram-groups.link', $group) }}" class="flex flex-wrap items-center gap-2 px-5 py-3 border-t border-slate-100">
                    @csrf
                    <label for="link-company" class="sr-only">Company</label>
                    <select id="link-company" name="client_id" required class="min-w-0 flex-1 sm:flex-none sm:w-80 border border-slate-300 rounded-lg px-2 py-1.5 text-sm">
                        <option value="">Choose a company…</option>
                        @foreach($companies as $c)<option value="{{ $c->id }}">{{ \Illuminate\Support\Str::limit($c->name, 70) }}{{ $c->inn ? ' · '.$c->inn : '' }}</option>@endforeach
                    </select>
                    <button class="{{ $dark }}">Link</button>
                </form>
            @endif
        </section>

        {{-- People who wrote there. --}}
        <section class="{{ $card }}">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide px-5 pt-5 pb-1">People who wrote here</h2>
            <p class="text-xs text-slate-400 px-5 pb-3">From the recorded messages. A person has a contact page once they write to the bot or the Premium account.</p>
            <ul class="divide-y divide-slate-100 text-sm">
                @forelse($members as $senderId => $m)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5">
                        @if($m['user'])
                            <a href="{{ route('admin.telegram-users.show', $m['user']) }}" class="font-medium text-slate-900 hover:underline">{{ $m['user']->displayName() ?? $m['name'] ?: 'Contact' }}</a>
                            @if($m['user']->contact_type)<span class="text-[11px] rounded-full px-2 py-0.5 border border-slate-200 text-slate-500">{{ \App\Models\TelegramUser::TYPES[$m['user']->contact_type] ?? $m['user']->contact_type }}</span>@endif
                        @else
                            <span class="font-medium text-slate-700">{{ $m['name'] ?: 'Unknown' }}</span>
                            <span class="text-[11px] rounded-full px-2 py-0.5 border border-slate-200 text-slate-400">no contact page</span>
                        @endif
                        <span class="text-xs text-slate-400 font-mono">{{ $senderId }}</span>
                        <span class="ml-auto text-xs text-slate-500">{{ $m['messages'] }} {{ Str::plural('message', $m['messages']) }}@if($m['last']) · {{ rescue(fn () => \Illuminate\Support\Carbon::parse($m['last'])->diffForHumans(), '', false) }}@endif</span>
                    </li>
                @empty
                    <li class="px-5 py-3 text-slate-500">{{ $group->neverRecorded() ? 'This group is not recorded (it is a working chat of the system), so its members are not known.' : 'Nobody has written here since the bot joined.' }}</li>
                @endforelse
            </ul>
        </section>

        {{-- Recorded sessions. --}}
        @if($conversations->isNotEmpty())
            <section class="{{ $card }}">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide px-5 pt-5 pb-3">Recorded conversations</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach($conversations as $c)
                        <li><a href="{{ route('admin.chat-conversations.show', $c) }}" class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50">
                            <span class="text-slate-700">{{ $c->created_at->format('d.m.Y H:i') }} – {{ $c->updated_at->format('d.m.Y H:i') }}</span>
                            <span class="ml-auto text-xs text-slate-500">{{ $c->message_count }} {{ Str::plural('message', $c->message_count) }}</span>
                        </a></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    <div class="space-y-4">
        <section class="{{ $card }} p-5 text-sm space-y-2">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Group</h2>
            <p><span class="text-slate-500">ID</span> <span class="font-mono text-slate-900">{{ $group->chat_id }}</span></p>
            <p><span class="text-slate-500">Type</span> <span class="text-slate-900">{{ ucfirst($group->type) }}</span>@if($group->username) · <span class="text-slate-900">{{ '@'.$group->username }}</span>@endif</p>
            <p><span class="text-slate-500">Bot</span> <span class="text-slate-900">{{ $status[$group->bot_status] ?? ($group->bot_status ?: 'In the group (status not reported yet)') }}</span></p>
            <p><span class="text-slate-500">Last activity</span> <span class="text-slate-900">{{ $group->last_seen_at?->format('d.m.Y H:i') ?? '—' }}</span></p>
        </section>

        <section class="{{ $card }} p-5 space-y-3">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Recording</h2>
            @if($group->neverRecorded())
                <p class="text-sm text-slate-600">This group is a working chat of the system ({{ implode(', ', array_map(fn ($r) => \App\Models\TelegramChat::ROLE_NAMES[$r], $group->neverRecordedRoles())) }}): its messages are never recorded as conversations.</p>
            @else
                <p class="text-sm text-slate-600">{{ $group->is_recorded ? 'New messages are recorded (Conversations, INNs found, members).' : 'New messages are not recorded. What is stored stays.' }}</p>
                <form method="POST" action="{{ route('admin.telegram-groups.recording', $group) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="is_recorded" value="{{ $group->is_recorded ? 0 : 1 }}">
                    <button class="{{ $group->is_recorded ? $btn : $dark }}">{{ $group->is_recorded ? 'Stop recording' : 'Record again' }}</button>
                </form>
                <p class="text-xs text-slate-400">The bot reads group messages only when its privacy mode is off (BotFather → /setprivacy → Disable) or it is a group admin.</p>
            @endif
        </section>

        @unless($group->isGone())
            <section class="{{ $card }} p-5 space-y-3">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Leave the group</h2>
                <p class="text-sm text-slate-600">The bot leaves the group. Stored conversations and company links stay; to come back, add the bot again in Telegram.</p>
                <form method="POST" action="{{ route('admin.telegram-groups.leave', $group) }}" onsubmit="return confirm('Make the bot leave this group?')">
                    @csrf
                    <button class="border border-slate-300 text-slate-700 bg-white text-xs font-medium rounded-lg px-3 py-1.5 hover:border-red-300 hover:text-red-700 hover:bg-red-50 disabled:opacity-50 disabled:pointer-events-none" @disabled($roles)>Leave the group</button>
                </form>
                @if($roles)<p class="text-xs text-slate-400">Not possible while the group is used as {{ implode(', ', array_map(fn ($r) => \App\Models\TelegramChat::ROLE_NAMES[$r], $roles)) }}.</p>@endif
            </section>
        @endunless
    </div>
</div>
@endsection
