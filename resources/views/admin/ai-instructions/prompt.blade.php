@extends('admin.layout')
@section('title', 'AI prompt preview')
@section('heading', 'AI prompt preview')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <a href="{{ route('admin.ai-instructions.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; AI rules &amp; info</a>
    <div class="flex flex-wrap gap-2">
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1" aria-label="Channel">
            @foreach(['web' => 'Website', 'telegram' => 'Telegram'] as $key => $label)
                <a href="{{ route('admin.ai-instructions.prompt', ['channel' => $key, 'locale' => $locale]) }}" @if($channel === $key) aria-current="true" @endif
                   class="text-xs font-medium rounded-md px-3 py-1.5 {{ $channel === $key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900' }}">{{ $label }}</a>
            @endforeach
        </nav>
        <nav class="flex gap-1 bg-slate-100 rounded-lg p-1" aria-label="Language">
            @foreach(['uz', 'ru', 'en'] as $key)
                <a href="{{ route('admin.ai-instructions.prompt', ['channel' => $channel, 'locale' => $key]) }}" @if($locale === $key) aria-current="true" @endif
                   class="text-xs font-medium uppercase rounded-md px-3 py-1.5 {{ $locale === $key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-900' }}">{{ $key }}</a>
            @endforeach
        </nav>
    </div>
</div>

<p class="text-xs text-slate-500 mb-3">
    This is exactly what the AI receives before each customer question ({{ number_format(mb_strlen($prompt)) }} characters).
    Changes to documents, the knowledge base, articles and company info appear here within 10 minutes, or right after saving.
</p>
<pre class="bg-white border border-slate-200 rounded-xl p-5 text-xs leading-relaxed text-slate-800 whitespace-pre-wrap break-words overflow-x-auto">{{ $prompt }}</pre>
@endsection
