@extends('admin.layout')
@section('title', 'Chat Leads')
@section('heading', 'Chat Leads')

@section('content')
<p class="text-xs text-slate-500 mb-3">Callback requests left by visitors in the website chat and the Telegram bot.</p>
<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($leads->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No leads yet</p>
            <p class="text-xs text-slate-400 mt-1">When visitors request a callback from the chat, they appear here.</p>
        </div>
    @else
        <div class="divide-y divide-slate-100">
            @foreach($leads as $lead)
                <div id="lead-{{ $lead->id }}" class="p-4 hover:bg-slate-50 target:bg-amber-50 scroll-mt-20" x-data="{ open: false }">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="font-medium text-slate-900">{{ $lead->name }}</span>
                        @if(filled($lead->phone))
                            <a href="tel:{{ $lead->phone }}" class="text-sm text-slate-600 hover:text-slate-900">{{ $lead->phone }}</a>
                        @else
                            <span class="text-sm text-slate-400">no phone yet</span>
                        @endif
                        <span class="text-xs uppercase text-slate-400">{{ $lead->locale }}</span>
                        @if($lead->channel === 'telegram')
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full border border-slate-300 text-slate-600">Telegram</span>
                        @endif
                        <span class="text-xs text-slate-400">{{ $lead->created_at->format('d.m.Y H:i') }}</span>
                        <div class="ml-auto flex items-center gap-3">
                            <form method="POST" action="{{ route('admin.chat-leads.update', $lead) }}">
                                @csrf @method('PUT')
                                <button class="text-xs font-medium px-2 py-0.5 rounded-full {{ $lead->is_handled ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }}"
                                        title="Toggle status">
                                    {{ $lead->is_handled ? 'Handled' : 'New' }}
                                </button>
                            </form>
                            @php
                                // Website / old bot leads keep the chat (a list of role + content); sales assistant leads keep
                                // the request details (items, city, company…) instead.
                                $stored = is_array($lead->transcript) ? $lead->transcript : [];
                                $chat = array_is_list($stored) ? array_values(array_filter($stored, fn ($m) => is_array($m) && is_string($m['content'] ?? null))) : [];
                                $details = array_is_list($stored) ? [] : $stored;
                                $scalar = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? (string) $v : null;
                                $detailRows = array_filter(['Company' => $scalar($details['company'] ?? null), 'City' => $scalar($details['city'] ?? null),
                                    'Business' => $scalar($details['business_type'] ?? null), 'Software' => $scalar($details['software'] ?? null)]);
                                $detailItems = array_values(array_filter(array_map($scalar, is_array($details['items'] ?? null) ? $details['items'] : [])));
                                $conversationId = is_numeric($details['conversation_id'] ?? null) ? (int) $details['conversation_id'] : null;
                            @endphp
                            @if($chat || $detailRows || $detailItems)
                                <button type="button" @click="open = !open" :aria-expanded="open" class="text-xs text-slate-500 hover:text-slate-900">{{ $chat ? 'Transcript' : 'Details' }}</button>
                            @endif
                            <form method="POST" action="{{ route('admin.chat-leads.destroy', $lead) }}" onsubmit="return confirm('Delete this lead?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-slate-400 hover:text-red-600">Delete</button>
                            </form>
                        </div>
                    </div>
                    @if($lead->topic)
                        <p class="text-sm text-slate-600 mt-1">{{ $lead->topic }}</p>
                    @endif
                    @if($lead->page_url)
                        <p class="text-xs text-slate-400 mt-1 break-all">{{ $lead->page_url }}</p>
                    @endif
                    @if($chat)
                        <div x-show="open" x-cloak class="mt-3 space-y-1.5 text-sm bg-slate-50 border border-slate-100 rounded-lg p-3">
                            @foreach($chat as $m)
                                @php $role = is_string($m['role'] ?? null) ? $m['role'] : 'user'; @endphp
                                <p><span class="text-xs font-semibold {{ $role === 'user' ? 'text-slate-900' : 'text-slate-400' }}">{{ match($role) { 'user' => 'Visitor', 'admin' => 'BDB team', default => 'Belgi' } }}:</span> {{ $m['content'] }}</p>
                            @endforeach
                        </div>
                    @elseif($detailRows || $detailItems)
                        <div x-show="open" x-cloak class="mt-3 space-y-2 text-sm bg-slate-50 border border-slate-100 rounded-lg p-3">
                            @if($detailItems)
                                <ul class="list-disc pl-5 text-slate-700">@foreach($detailItems as $item)<li>{{ $item }}</li>@endforeach</ul>
                            @endif
                            @if($detailRows)
                                <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1">
                                    @foreach($detailRows as $label => $value)
                                        <dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="text-slate-800 break-words">{{ $value }}</dd>
                                    @endforeach
                                </dl>
                            @endif
                            @if($conversationId && \App\Models\ChatConversation::whereKey($conversationId)->exists())
                                <a href="{{ route('admin.chat-conversations.show', $conversationId) }}" class="text-xs underline text-slate-600 hover:text-slate-900">Open the conversation</a>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
<div class="mt-4">{{ $leads->links() }}</div>
@endsection
