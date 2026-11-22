@extends('admin.layout')
@section('title', 'Shadow replies')
@section('heading', 'AI Sales & Support Team — Shadow replies')

@php use App\Models\AiShadowDraft; @endphp

@section('content')
<div class="w-full space-y-6">
    <p class="text-sm text-slate-600 max-w-3xl">
        An AI employee in <span class="font-medium text-slate-800">Shadow</span> status answers real customers only through you: each reply waits here
        (and on the conversation page) until a manager sends it — as written or edited — or discards it. When most replies go out unchanged,
        the employee is ready to go <span class="font-medium text-slate-800">Live</span>.
    </p>

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($employees as ['employee' => $e, 'stats' => $s])
            <a href="{{ route('admin.ai-employees.edit', $e) }}" class="bg-white border border-slate-200 rounded-xl p-4 hover:border-slate-400">
                <p class="font-semibold text-slate-900">{{ $e->name }} <span class="ml-1 text-xs font-normal text-slate-500">{{ \App\Models\AiEmployee::STATUSES[$e->status] ?? $e->status }}</span></p>
                <p class="text-xs text-slate-500">{{ $e->role }}</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $s['rate'] !== null ? $s['rate'].'%' : '—' }}</p>
                <p class="text-xs text-slate-500">sent unchanged · {{ $s['decided'] }} decided ({{ $s['edited'] }} edited, {{ $s['discarded'] }} discarded)</p>
            </a>
        @endforeach
    </div>

    <section class="space-y-3" aria-labelledby="pending-h">
        <h2 id="pending-h" class="text-sm font-semibold text-slate-900">Waiting for a manager ({{ $pending->count() }})</h2>
        @forelse ($pending as $draft)
            <div class="bg-white border border-slate-200 rounded-xl p-4 space-y-2">
                @if ($draft->conversation)
                    <a href="{{ route('admin.chat-conversations.show', $draft->conversation) }}" class="text-sm font-medium text-slate-900 underline">{{ $draft->conversation->chatName() }} — open the conversation</a>
                @endif
                @include('admin.ai-team._shadow-draft', ['draft' => $draft])
            </div>
        @empty
            <p class="text-sm text-slate-500">Nothing is waiting.</p>
        @endforelse
    </section>

    @if ($decided->isNotEmpty())
        <section class="bg-white border border-slate-200 rounded-xl overflow-x-auto" aria-labelledby="decided-h">
            <h2 id="decided-h" class="px-5 py-3 text-sm font-semibold text-slate-900 border-b border-slate-100">Recent decisions</h2>
            <table class="w-full text-sm">
                <caption class="sr-only">Recent decisions on shadow replies</caption>
                <thead class="text-xs text-slate-500 text-left"><tr><th class="px-5 py-2 font-medium">When</th><th class="px-3 py-2 font-medium">AI employee</th><th class="px-3 py-2 font-medium">Decision</th><th class="px-3 py-2 font-medium">AI wrote → sent</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($decided as $d)
                        <tr>
                            <td class="px-5 py-2.5 whitespace-nowrap">{{ $d->decided_at?->timezone(config('app.timezone'))->format('d.m H:i') }}<br><span class="text-xs text-slate-500">{{ $d->decided_by }}</span></td>
                            <td class="px-3 py-2.5 whitespace-nowrap">{{ $d->employee?->name ?? '—' }}</td>
                            <td class="px-3 py-2.5 whitespace-nowrap">{{ AiShadowDraft::STATUSES[$d->status] ?? $d->status }}</td>
                            <td class="px-3 py-2.5 min-w-[16rem]">
                                <p class="text-slate-700 {{ $d->status !== 'sent' ? 'line-through decoration-slate-400' : '' }}">{{ \Illuminate\Support\Str::limit($d->draft, 300) }}</p>
                                @if ($d->status === 'edited')<p class="mt-1 text-slate-900">→ {{ \Illuminate\Support\Str::limit($d->final, 300) }}</p>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
@endsection
