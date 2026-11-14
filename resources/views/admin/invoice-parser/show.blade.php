@extends('admin.layout')
@section('title', 'Invoice message')
@section('heading', 'Invoice parser — Photo #'.$invoice->message_id)

@php
    $r = is_array($invoice->result) ? \App\Services\Invoices\InvoiceParser::clean($invoice->result) : [];
    $accepted = ($r['status'] ?? null) === 'accepted';
    $num = fn ($v) => is_numeric($v) ? number_format((float) $v, abs((float) $v - round((float) $v)) < 0.005 ? 0 : 2, ',', ' ') : ($v ?? '—');
@endphp

@section('content')
<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
    <div class="space-y-6 min-w-0">
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-2 text-sm">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-slate-100 text-slate-700">{{ \App\Models\InvoiceMessage::STATUSES[$invoice->status] ?? $invoice->status }}</span>
                <span class="text-slate-500">{{ $invoice->created_at->format('d.m.Y H:i') }}@if($invoice->sender) · {{ $invoice->sender }}@endif</span>
                <span class="text-slate-400">· attempts {{ $invoice->attempts }}/{{ \App\Models\InvoiceMessage::MAX_ATTEMPTS }}</span>
            </div>
            @if($invoice->caption)<p class="text-slate-600">Caption: {{ $invoice->caption }}</p>@endif
            @if($invoice->error)<p class="text-red-600 break-words">{{ $invoice->error }}</p>@endif
            @if(($r['status'] ?? null) === 'skipped')<p class="text-slate-600">Not an invoice: {{ $r['reason'] ?? '—' }}</p>@endif
            @if($invoice->model)
                <p class="text-xs text-slate-400">{{ $invoice->model }} · {{ number_format((int) $invoice->input_tokens) }} in / {{ number_format((int) $invoice->output_tokens) }} out tokens</p>
            @endif
        </div>

        @if($accepted)
            <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4 text-sm">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div><p class="text-xs text-slate-400">Invoice</p><p class="font-medium text-slate-900">№ {{ $r['invoice_number'] ?? '—' }} · {{ $r['invoice_date'] ?? '—' }}</p></div>
                    <div><p class="text-xs text-slate-400">Supplier</p><p class="text-slate-900">{{ $r['supplier']['name'] ?? '—' }}</p><p class="text-xs text-slate-500">STIR {{ $r['supplier']['inn'] ?? '—' }}@if(filled($r['supplier']['vat_code'] ?? null)) · QQS {{ $r['supplier']['vat_code'] }}@endif</p></div>
                    <div><p class="text-xs text-slate-400">Buyer</p><p class="text-slate-900">{{ $r['buyer']['name'] ?? '—' }}</p><p class="text-xs text-slate-500">STIR {{ $r['buyer']['inn'] ?? '—' }}</p></div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-slate-400 text-left"><tr>
                            <th class="py-2 pr-3">#</th><th class="py-2 pr-3">Name</th><th class="py-2 pr-3">Unit</th><th class="py-2 pr-3 text-right">Qty</th>
                            <th class="py-2 pr-3 text-right">Price</th><th class="py-2 pr-3 text-right">Amount</th><th class="py-2 pr-3 text-right">VAT %</th><th class="py-2 pr-3 text-right">VAT</th><th class="py-2 text-right">With VAT</th>
                        </tr></thead>
                        <tbody class="divide-y divide-slate-100 tabular-nums">
                            @foreach(array_values(array_filter((array) ($r['items'] ?? []), 'is_array')) as $i => $item)
                                <tr>
                                    <td class="py-2 pr-3 text-slate-400">{{ $i + 1 }}</td>
                                    <td class="py-2 pr-3 text-slate-900">{{ $item['name'] ?? '—' }}</td>
                                    <td class="py-2 pr-3">{{ $item['unit'] ?? '' }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $num($item['quantity'] ?? null) }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $num($item['price'] ?? null) }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $num($item['amount'] ?? null) }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $item['vat_rate'] ?? '' }}</td>
                                    <td class="py-2 pr-3 text-right">{{ $num($item['vat_amount'] ?? null) }}</td>
                                    <td class="py-2 text-right">{{ $num($item['total_with_vat'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap justify-end gap-6 tabular-nums">
                    <span>Amount: <b>{{ $num($r['total_amount'] ?? null) }}</b></span>
                    <span>VAT: <b>{{ $num($r['total_vat'] ?? null) }}</b></span>
                    <span>Total: <b>{{ $num($r['total_with_vat'] ?? null) }}</b></span>
                </div>
                @if(filled($r['amount_in_words'] ?? null))<p class="text-xs text-slate-500">{{ $r['amount_in_words'] }}</p>@endif
                @if(filled($r['warning'] ?? null))<p class="text-xs text-amber-700">Warning: {{ $r['warning'] }}</p>@endif
            </div>

            <div class="bg-white border border-slate-200 rounded-xl p-6">
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Message posted to the destination chat</h2>
                <pre class="whitespace-pre-wrap text-xs text-slate-700 font-sans">{{ strip_tags(\App\Services\Invoices\InvoiceParser::summary($r)) }}</pre>
            </div>
        @endif

        @if($r)
            <details class="bg-white border border-slate-200 rounded-xl p-6">
                <summary class="text-xs font-semibold text-slate-500 uppercase tracking-wide cursor-pointer">Raw JSON from Claude</summary>
                <pre class="mt-3 overflow-x-auto text-xs text-slate-700">{{ json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
    </div>

    <aside class="space-y-4">
        <div class="bg-white border border-slate-200 rounded-xl p-3">
            @if($invoice->mime === 'application/pdf')
                <a href="{{ route('admin.invoice-messages.image', $invoice) }}" target="_blank" rel="noopener" class="block p-6 text-center text-sm underline">Open the PDF</a>
            @else
                <a href="{{ route('admin.invoice-messages.image', $invoice) }}" target="_blank" rel="noopener">
                    <img src="{{ route('admin.invoice-messages.image', $invoice) }}" alt="Photo #{{ $invoice->message_id }}" class="w-full rounded-lg" loading="lazy">
                </a>
            @endif
        </div>
        <form method="POST" action="{{ route('admin.invoice-messages.retry', $invoice) }}">
            @csrf
            <button type="submit" class="w-full text-sm font-medium rounded-lg px-4 py-2 border border-slate-300 hover:border-slate-500"
                    @if($invoice->status === 'sent') onclick="return confirm('Read it again? A new summary will be posted to the destination chat.')" @endif>
                Read again with Claude
            </button>
        </form>
        <form method="POST" action="{{ route('admin.invoice-messages.destroy', $invoice) }}" onsubmit="return confirm('Delete this entry from the list?')">
            @csrf @method('DELETE')
            <button type="submit" class="w-full text-sm text-red-600 hover:text-red-700">Delete from the list</button>
        </form>
        <a href="{{ route('admin.invoice-messages.index') }}" class="block text-center text-sm text-slate-500 hover:text-slate-700">← All messages</a>
    </aside>
</div>
@endsection
