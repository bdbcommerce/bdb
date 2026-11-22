@extends('admin.layout')
@section('title', 'Sales report')
@section('heading', ($report->period === 'day' ? 'Daily' : 'Weekly').' sales report — '.$report->period_end->copy()->setTimezone('Asia/Tashkent')->format('d.m.Y'))

@section('content')
<div class="w-full space-y-4">
    <a href="{{ route('admin.sales-report.index') }}" class="text-sm underline text-slate-600">← All reports</a>
    <p class="text-sm text-slate-500">{{ $report->conversations }} conversations · {{ $report->status }} · {{ \App\Services\Invoices\ClaudePricing::format($report->cost_usd) }}{{ $report->chat_id ? ' · sent to '.$report->chat_id : '' }}</p>
    {{-- The Telegram HTML: every text was escaped when it was built; safeMessages() keeps only plain tags and http(s) links. --}}
    @foreach ($report->safeMessages() as $message)
        <div class="bg-white border border-slate-200 rounded-xl p-5 text-sm text-slate-800 whitespace-pre-line break-words [&_a]:underline" data-message>{!! $message !!}</div>
    @endforeach
</div>
@endsection
