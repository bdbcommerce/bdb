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
                        <a href="tel:{{ $lead->phone }}" class="text-sm text-slate-600 hover:text-slate-900">{{ $lead->phone }}</a>
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
                            @if($lead->transcript)
                                <button type="button" @click="open = !open" class="text-xs text-slate-500 hover:text-slate-900">Transcript</button>
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
                    @if($lead->transcript)
                        <div x-show="open" x-cloak class="mt-3 space-y-1.5 text-sm bg-slate-50 border border-slate-100 rounded-lg p-3">
                            @foreach($lead->transcript as $m)
                                <p><span class="text-xs font-semibold {{ $m['role'] === 'user' ? 'text-slate-900' : 'text-slate-400' }}">{{ match($m['role']) { 'user' => 'Visitor', 'admin' => 'BDB team', default => 'Belgi' } }}:</span> {{ $m['content'] }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
<div class="mt-4">{{ $leads->links() }}</div>
@endsection
