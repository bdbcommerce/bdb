@extends('admin.layout')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
<div class="mb-6">
    <h2 class="text-lg font-semibold text-slate-900">Welcome back, {{ auth()->user()->name ?? 'Admin' }}</h2>
    <p class="text-sm text-slate-500 mt-0.5">Here's a quick overview of your content.</p>
</div>

{{-- Stat cards --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    @php
        $statCards = [
            [
                'label' => 'Articles',
                'value' => $stats['articles'],
                'sub'   => $stats['published_articles'] . ' published',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>',
            ],
            [
                'label' => 'Brands',
                'value' => $stats['brands'],
                'sub'   => 'partner brands',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 6h.008v.008H6V6z"/>',
            ],
            [
                'label' => 'Customers',
                'value' => $stats['customers'],
                'sub'   => 'active clients',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"/>',
            ],
            [
                'label' => 'Banners',
                'value' => $stats['banners'],
                'sub'   => 'hero slides',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>',
            ],
        ];
    @endphp
    @foreach ($statCards as $card)
        <div class="bg-white border border-slate-200 rounded-xl p-5">
            <div class="flex items-start justify-between mb-3">
                <p class="text-2xl font-bold text-slate-900">{{ $card['value'] }}</p>
                <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        {!! $card['icon'] !!}
                    </svg>
                </div>
            </div>
            <p class="text-sm font-medium text-slate-700">{{ $card['label'] }}</p>
            <p class="text-xs text-slate-400 mt-0.5">{{ $card['sub'] }}</p>
        </div>
    @endforeach
</div>

{{-- AI Assistant --}}
<div class="mb-3 flex items-center justify-between">
    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">AI Assistant</h3>
    <a href="{{ route('admin.chat-leads.index') }}" class="text-xs font-medium text-slate-500 hover:text-slate-900">All leads &rarr;</a>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">
    <div class="grid grid-cols-2 lg:grid-cols-1 gap-4">
        <a href="{{ route('admin.chat-leads.index') }}" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-slate-400 transition-colors">
            <div class="flex items-start justify-between mb-3">
                <p class="text-2xl font-bold text-slate-900">{{ $stats['new_leads'] }}</p>
                <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/>
                    </svg>
                </div>
            </div>
            <p class="text-sm font-medium text-slate-700">New chat leads</p>
            <p class="text-xs text-slate-400 mt-0.5">{{ $stats['leads'] }} total</p>
        </a>
        <a href="{{ route('admin.knowledge.index') }}" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-slate-400 transition-colors">
            <div class="flex items-start justify-between mb-3">
                <p class="text-2xl font-bold text-slate-900">{{ $stats['active_knowledge'] }}</p>
                <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/>
                    </svg>
                </div>
            </div>
            <p class="text-sm font-medium text-slate-700">Knowledge entries</p>
            <p class="text-xs text-slate-400 mt-0.5">{{ $stats['knowledge'] }} total, {{ $stats['active_knowledge'] }} active</p>
        </a>
    </div>

    <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl">
        <div class="px-5 py-3 border-b border-slate-100">
            <p class="text-sm font-medium text-slate-700">Recent chat leads</p>
        </div>
        @forelse ($recentLeads as $lead)
            <a href="{{ route('admin.chat-leads.index') }}" class="flex items-center gap-3 px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50">
                <span class="w-2 h-2 rounded-full shrink-0 {{ $lead->is_handled ? 'bg-slate-200' : 'bg-slate-900' }}"
                      title="{{ $lead->is_handled ? 'Handled' : 'New' }}"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-slate-800 truncate">{{ $lead->name ?: 'Anonymous' }} <span class="font-normal text-slate-500">{{ $lead->phone }}</span></p>
                    <p class="text-xs text-slate-400 truncate">{{ $lead->topic ?: 'No topic' }}</p>
                </div>
                <span class="text-xs text-slate-400 shrink-0">{{ $lead->created_at->diffForHumans() }}</span>
            </a>
        @empty
            <p class="px-5 py-8 text-sm text-slate-400 text-center">No chat leads yet. They appear here when visitors leave contact details in the AI chat.</p>
        @endforelse
    </div>
</div>

{{-- Quick links --}}
<div class="mb-3">
    <h3 class="text-xs font-semibold uppercase tracking-widest text-slate-400">Quick links</h3>
</div>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
    @php
        $shortcuts = [
            [
                'href'  => route('admin.articles.create'),
                'label' => 'Add Article',
                'desc'  => 'Write and publish a new article',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15"/>',
            ],
            [
                'href'  => route('admin.banners.create'),
                'label' => 'Add Banner',
                'desc'  => 'Add a new hero banner slide',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15"/>',
            ],
            [
                'href'  => route('admin.company-info.edit'),
                'label' => 'Edit Company Info',
                'desc'  => 'Update contact details and about text',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/>',
            ],
            [
                'href'  => route('admin.knowledge.create'),
                'label' => 'Add Knowledge Entry',
                'desc'  => 'Teach the AI assistant a new answer',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.5v15m7.5-7.5h-15"/>',
            ],
            [
                'href'  => route('admin.chat-leads.index'),
                'label' => 'Review Chat Leads',
                'desc'  => 'Follow up on visitors from the AI chat',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>',
            ],
        ];
    @endphp
    @foreach ($shortcuts as $s)
        <a href="{{ $s['href'] }}"
           class="group bg-white border border-slate-200 rounded-xl px-5 py-4 flex items-center gap-4 hover:border-slate-400 hover:shadow-sm transition-all">
            <div class="w-8 h-8 rounded-lg bg-slate-100 group-hover:bg-slate-200 flex items-center justify-center shrink-0 transition-colors">
                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    {!! $s['icon'] !!}
                </svg>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-800 group-hover:text-slate-900">{{ $s['label'] }}</p>
                <p class="text-xs text-slate-400 mt-0.5">{{ $s['desc'] }}</p>
            </div>
            <svg class="w-4 h-4 text-slate-300 group-hover:text-slate-500 ml-auto shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    @endforeach
</div>
@endsection
