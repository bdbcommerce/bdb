@extends('admin.layout')
@section('title', 'Order #'.$order->id)
@section('heading', 'Order #'.$order->id)

@php use App\Models\Order; $user = $order->telegramUser; $company = (array) $order->company; @endphp

@section('content')
<div class="w-full max-w-3xl space-y-4">
    <div class="flex flex-wrap items-center gap-4 text-sm print:hidden">
        @if ($user)<a href="{{ route('admin.telegram-users.show', $user) }}" class="text-slate-600 underline">Back to the contact</a>@endif
        @if ($order->conversation)<a href="{{ route('admin.chat-conversations.show', $order->conversation) }}" class="text-slate-600 underline">The chat</a>@endif
        <button type="button" onclick="window.print()" class="border border-slate-300 rounded-lg px-3 py-1.5 bg-white hover:bg-slate-50">Print</button>
    </div>

    <article class="bg-white border border-slate-200 rounded-xl p-6 space-y-5 text-sm">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Order #{{ $order->id }}</h2>
                <p class="text-slate-500">{{ ($order->confirmed_at ?? $order->created_at)->setTimezone(\App\Models\SalesAssistantSetting::TZ)->format('d.m.Y H:i') }}</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium {{ $order->status === 'confirmed' ? 'bg-slate-900 text-white' : 'border border-slate-300 text-slate-600' }}">{{ Order::STATUSES[$order->status] ?? $order->status }}</span>
        </header>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Customer</p>
                <p class="text-slate-900">{{ $user?->displayName() ?? '—' }}</p>
                <p class="text-slate-600">{{ $user?->phone ?? '' }}{{ $user?->username ? ' · @'.$user->username : '' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Company</p>
                @if ($company)
                    <p class="text-slate-900">{{ $company['name'] ?? '' }}</p>
                    @if (! empty($company['full_name']) && $company['full_name'] !== ($company['name'] ?? null))<p class="text-slate-600">{{ $company['full_name'] }}</p>@endif
                    <p class="text-slate-600">{{ collect([! empty($company['inn']) ? 'INN '.$company['inn'] : null, $company['address'] ?? null])->filter()->implode(' · ') }}</p>
                @else
                    <p class="text-slate-500">Private person (no company linked by a manager)</p>
                @endif
            </div>
        </div>

        <table class="w-full">
            <caption class="sr-only">Items</caption>
            <thead class="text-xs text-slate-500 text-left border-b border-slate-200"><tr><th class="py-2 font-medium w-8">#</th><th class="py-2 font-medium">Item</th><th class="py-2 font-medium">Details</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ((array) $order->items as $n => $item)
                    <tr><td class="py-2 text-slate-400">{{ $n + 1 }}</td><td class="py-2 text-slate-900">{{ $item['product'] ?? '' }}</td><td class="py-2 text-slate-600">{{ $item['details'] ?? '' }}</td></tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500">No items.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($order->quoted)
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Prices the AI quoted</p>
                @foreach ((array) $order->quoted as $q)<p class="text-slate-700">{{ $q['product'] ?? '' }} — {{ $q['price'] ?? '' }}</p>@endforeach
                <p class="text-xs text-slate-400 mt-1">From the product catalog at the time; the manager's invoice is final.</p>
            </div>
        @endif
        @if ($order->summary)<p class="text-slate-700 whitespace-pre-line">{{ $order->summary }}</p>@endif
        @if ($order->lead)<p class="text-xs text-slate-500">Request #{{ $order->lead->id }}{{ $order->lead->taken_by ? ' · taken by '.$order->lead->taken_by : '' }}</p>@endif
    </article>
</div>
@endsection
