@extends('admin.layout')
@section('title', 'Telegram groups')
@section('heading', 'Telegram groups')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500 max-w-2xl">Every group the bot is in or was in. A group appears as soon as the bot is added, or when someone posts there. Open a group to see what it is used for, link companies, see who writes there, switch recording off or make the bot leave.</p>
</div>

<form method="GET" action="{{ route('admin.telegram-groups.index') }}" class="flex gap-2 mb-4" role="search">
    <label for="group-search" class="sr-only">Search groups</label>
    <input id="group-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Group name or ID"
           class="w-full max-w-md border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
    <button type="submit" class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50">Search</button>
    @if($search !== '')
        <a href="{{ route('admin.telegram-groups.index') }}" class="self-center text-xs text-slate-500 underline hover:text-slate-900">Clear</a>
    @endif
</form>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($groups->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">{{ $search !== '' ? 'No groups match your search' : 'No groups yet' }}</p>
            <p class="text-xs text-slate-400 mt-1">Add the bot to a Telegram group — it appears here at once.</p>
        </div>
    @else
        <div class="divide-y divide-slate-100">
            @foreach($groups as $group)
                @php $s = $sessions[$group->chat_id] ?? null; $roles = $group->roles(); @endphp
                <a href="{{ route('admin.telegram-groups.show', $group) }}" class="flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-1.5 px-4 py-3 hover:bg-slate-50">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full border border-slate-300 text-xs font-semibold text-slate-600 uppercase" aria-hidden="true">{{ mb_substr($group->title ?: '#', 0, 1) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-900 truncate">{{ $group->title ?: 'Untitled group' }}</p>
                        <p class="text-xs text-slate-500 truncate font-mono">{{ $group->chat_id }}@if($group->username) <span class="font-sans">· {{ '@'.$group->username }}</span>@endif</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 shrink-0 ml-13 sm:ml-0">
                        @foreach($roles as $role)
                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5 bg-slate-900 text-white">{{ \App\Models\TelegramChat::ROLE_NAMES[$role] }}</span>
                        @endforeach
                        @if($group->isGone())
                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5 border border-slate-400 text-slate-700">Bot removed</span>
                        @elseif($group->bot_status === 'administrator')
                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5 border border-slate-200 text-slate-500">Bot is admin</span>
                        @endif
                        @if(! $group->is_recorded)
                            <span class="text-[11px] font-medium rounded-full px-2 py-0.5 border border-slate-400 text-slate-700">Not recorded</span>
                        @endif
                        @if($links[$group->chat_id] ?? 0)
                            <span class="text-xs text-slate-500">{{ $links[$group->chat_id] }} {{ Str::plural('company', $links[$group->chat_id]) }}</span>
                        @endif
                        <span class="text-xs text-slate-500">{{ (int) ($s->messages ?? 0) }} {{ Str::plural('message', (int) ($s->messages ?? 0)) }}</span>
                        <span class="text-xs text-slate-400 w-24 text-right">{{ $group->last_seen_at?->diffForHumans() ?? '—' }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
<div class="mt-4">{{ $groups->links() }}</div>
@endsection
