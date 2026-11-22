@extends('admin.layout')
@section('title', 'Trace #'.$trace->id)
@section('heading', 'AI Sales & Support Team — Trace #'.$trace->id)

@section('content')
<div class="w-full space-y-6">
    <div class="flex flex-wrap gap-4 text-sm">
        <a href="{{ route('admin.ai-team.traces', ['source' => $trace->source]) }}" class="text-slate-600 underline">All traces</a>
        @if ($trace->conversation)
            <a href="{{ route('admin.chat-conversations.show', $trace->conversation) }}" class="text-slate-600 underline">Open the conversation with {{ $trace->conversation->chatName() }}</a>
        @elseif ($trace->sandbox_chat_id)
            <a href="{{ route('admin.ai-team.sandbox', ['chat' => $trace->sandbox_chat_id, 'trace' => $trace->id]) }}" class="text-slate-600 underline">Open the sandbox chat</a>
        @endif
    </div>
    <section class="bg-white border border-slate-200 rounded-xl p-5">
        @include('admin.ai-team._trace', ['trace' => $trace])
    </section>
</div>
@endsection
