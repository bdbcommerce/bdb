@extends('admin.layout')
@section('title', 'Invoice messages')
@section('heading', 'Invoice parser — Messages')

@php
    $statusClass = fn (string $s) => match ($s) {
        'sent' => 'bg-green-50 text-green-700', 'skipped' => 'bg-slate-100 text-slate-500', 'failed' => 'bg-red-50 text-red-700',
        'processing' => 'bg-sky-50 text-sky-700', default => 'bg-amber-50 text-amber-700',
    };
    $settings = \App\Models\InvoiceParserSetting::current();
@endphp

@section('content')
@unless($settings->ready())
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        The invoice parser is {{ $settings->enabled ? 'not fully set up' : 'off' }}. <a href="{{ route('admin.invoice-parser.edit') }}" class="underline font-medium">Open settings</a>.
    </div>
@endunless

<div class="flex flex-wrap gap-1 mb-4">
    <a href="{{ route('admin.invoice-messages.index') }}"
       class="text-xs font-medium px-3 py-1.5 rounded-full border {{ ! $status ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400' }}">
        All <span class="tabular-nums">{{ $counts->sum() }}</span>
    </a>
    @foreach(\App\Models\InvoiceMessage::STATUSES as $key => $label)
        <a href="{{ route('admin.invoice-messages.index', ['status' => $key]) }}"
           class="text-xs font-medium px-3 py-1.5 rounded-full border {{ $status === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400' }}">
            {{ $label }} <span class="tabular-nums">{{ $counts[$key] ?? 0 }}</span>
        </a>
    @endforeach
</div>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($messages->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No photos yet</p>
            <p class="text-xs text-slate-400 mt-1">Photos posted in the source chat appear here with what Claude read from them.</p>
        </div>
    @else
        <ul class="divide-y divide-slate-100">
            @foreach($messages as $m)
                @php $r = is_array($m->result) ? \App\Services\Invoices\InvoiceParser::clean($m->result) : []; @endphp
                <li>
                    <a href="{{ route('admin.invoice-messages.show', $m) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4 hover:bg-slate-50">
                        <span class="font-medium text-slate-900">
                            @if(($r['status'] ?? null) === 'accepted')
                                № {{ $r['invoice_number'] ?? '—' }} · {{ $r['supplier']['name'] ?? '—' }}
                            @elseif(($r['status'] ?? null) === 'skipped')
                                <span class="font-normal text-slate-500">{{ $r['reason'] ?? 'Not an invoice' }}</span>
                            @else
                                <span class="font-normal text-slate-500">Photo #{{ $m->message_id }}</span>
                            @endif
                        </span>
                        @if(is_numeric($r['total_with_vat'] ?? null))
                            <span class="text-sm text-slate-600 tabular-nums">{{ number_format((float) $r['total_with_vat'], 0, ',', ' ') }} soʻm</span>
                        @endif
                        @if($m->status === 'failed')
                            <span class="text-xs text-red-600 min-w-0 truncate max-w-md">{{ $m->error }}</span>
                        @endif
                        <span class="ml-auto flex items-center gap-3">
                            @if($m->sender)<span class="text-xs text-slate-400">{{ $m->sender }}</span>@endif
                            <span class="text-xs text-slate-400">{{ $m->created_at->format('d.m.Y H:i') }}</span>
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusClass($m->status) }}">{{ \App\Models\InvoiceMessage::STATUSES[$m->status] ?? $m->status }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
<div class="mt-4">{{ $messages->links() }}</div>
@endsection
