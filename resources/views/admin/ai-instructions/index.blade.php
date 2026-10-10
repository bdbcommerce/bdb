@extends('admin.layout')
@section('title', 'AI rules & info')
@section('heading', 'AI rules & info')

@section('header-actions')
    <a href="{{ route('admin.ai-instructions.create') }}"
       class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">New document</a>
@endsection

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <p class="text-xs text-slate-500 max-w-2xl">
        Markdown documents sent to the AI with every question. <b>Rules</b> tell it how to behave (tone, what to ask,
        what never to say); <b>information</b> adds facts it may use. They never override the main rule: the AI only
        answers from the website data and these documents.
    </p>
    <div class="flex gap-2 text-xs">
        <a href="{{ route('admin.ai-instructions.prompt', ['channel' => 'web']) }}" class="border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Preview website prompt</a>
        <a href="{{ route('admin.ai-instructions.prompt', ['channel' => 'telegram']) }}" class="border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Preview Telegram prompt</a>
    </div>
</div>

<div class="bg-white border border-slate-300 rounded-xl p-5 mb-4 flex flex-wrap items-start gap-4">
    <div class="min-w-0 flex-1">
        <h2 class="text-sm font-semibold text-slate-900">Main prompt</h2>
        <p class="text-xs text-slate-500 mt-0.5">
            The AI's core instructions: who it is, the "answer only from website data" rule, tone and style.
            Reply language, length and the fields used by the requests inbox are added automatically.
        </p>
        <p class="text-xs text-slate-400 mt-1 truncate">
            {{ number_format(mb_strlen($main->content)) }} characters
            · {{ $main->content === \App\Services\ChatAssistant::DEFAULT_PROMPT ? 'default text' : 'edited '.$main->updated_at->diffForHumans() }}
        </p>
    </div>
    <a href="{{ route('admin.ai-instructions.edit', $main) }}"
       class="shrink-0 border border-slate-300 rounded-lg px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Edit main prompt</a>
</div>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($instructions->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No documents yet</p>
            <p class="text-xs text-slate-400 mt-1">For example: “Always greet by name”, “Never promise delivery dates”, “Office hours”.</p>
        </div>
    @else
        <ul class="divide-y divide-slate-100" role="list">
            @foreach($instructions as $instruction)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('admin.ai-instructions.edit', $instruction) }}" class="text-sm font-medium text-slate-900 hover:underline">{{ $instruction->title }}</a>
                        <p class="text-xs text-slate-400 mt-0.5 truncate">
                            {{ $instruction->kind === 'rules' ? 'Rules' : 'Information' }}
                            · {{ $channels[$instruction->channel] ?? $instruction->channel }}
                            · {{ number_format(mb_strlen($instruction->content)) }} characters
                        </p>
                    </div>
                    <span class="text-xs font-medium px-2 py-0.5 rounded-full border {{ $instruction->is_active ? 'border-slate-900 text-slate-900' : 'border-slate-200 text-slate-400' }}">
                        {{ $instruction->is_active ? 'Active' : 'Off' }}
                    </span>
                    <form method="POST" action="{{ route('admin.ai-instructions.destroy', $instruction) }}" onsubmit="return confirm('Delete this document?')">
                        @csrf @method('DELETE')
                        <button class="text-xs text-slate-400 hover:text-red-600">Delete</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
