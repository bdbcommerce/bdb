@extends('admin.layout')
@section('title', $client->name)
@section('heading', 'Client portrait — '.$client->name)

@php use App\Models\Client; use App\Models\ClientPerson; @endphp

@section('content')
<div class="w-full space-y-6">
    <div class="flex flex-wrap items-center gap-4 text-sm">
        <a href="{{ route('admin.clients.index') }}" class="text-slate-600 underline">All companies</a>
        <a href="{{ route('admin.clients.edit', $client) }}" class="text-slate-600 underline">Edit company</a>
        <a href="{{ route('admin.client-people.create', ['client' => $client->id]) }}" class="bg-slate-900 text-white font-medium rounded-lg px-3 py-1.5 hover:bg-slate-700">Add person</a>
    </div>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start gap-4">
            <span class="w-11 h-11 rounded-lg bg-slate-200 text-slate-700 font-semibold flex items-center justify-center shrink-0" aria-hidden="true">{{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}</span>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-slate-900">{{ $client->name }} <span class="ml-2 text-xs font-medium rounded-full border border-slate-300 px-2 py-0.5 text-slate-600">{{ Client::STATUSES[$client->status] ?? $client->status }}</span></h2>
                <p class="text-sm text-slate-600">{{ collect([$client->industry, $client->city, $client->size, $client->inn ? 'INN '.$client->inn : null])->filter()->implode(' · ') ?: '—' }}</p>
                <p class="text-xs text-slate-500">{{ $client->since ? 'Client since '.$client->since : '' }}{{ $client->since && $client->owner ? ' · ' : '' }}{{ $client->owner ? 'Looks after them: '.$client->owner : '' }}</p>
            </div>
        </div>
        <div class="grid lg:grid-cols-3">
            <div class="lg:col-span-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">People at {{ $client->name }}</caption>
                    <thead class="text-xs text-slate-500 text-left"><tr><th class="px-5 py-2 font-medium">Person</th><th class="px-3 py-2 font-medium">Department</th><th class="px-3 py-2 font-medium">In decisions</th><th class="px-3 py-2 font-medium">How to talk</th><th class="px-3 py-2 font-medium">Status</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($client->people as $p)
                            <tr class="{{ $p->status === 'active' ? '' : 'text-slate-500' }}">
                                <td class="px-5 py-2.5">
                                    <a href="{{ route('admin.client-people.edit', $p) }}" class="font-medium underline {{ $p->status === 'active' ? 'text-slate-900' : '' }}">{{ $p->name }}</a><br>
                                    <span class="text-xs text-slate-500">{{ $p->position ?: '—' }}@if($p->telegramUser) · <a href="{{ route('admin.telegram-users.show', $p->telegramUser) }}" class="underline">Telegram</a>@endif</span>
                                </td>
                                <td class="px-3 py-2.5">{{ $p->department ?: '—' }}</td>
                                <td class="px-3 py-2.5">{{ $p->part ? (ClientPerson::PARTS[$p->part] ?? $p->part) : '—' }}</td>
                                <td class="px-3 py-2.5 text-xs">{{ $p->style ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-xs">
                                    <span class="rounded-md border px-2 py-0.5 {{ $p->status === 'active' ? 'border-slate-900 text-slate-900' : 'border-dashed border-slate-400' }}">{{ ClientPerson::STATUSES[$p->status] ?? $p->status }}</span>
                                    @if ($p->next?->client)<p class="mt-1">Now at <a href="{{ route('admin.clients.show', $p->next->client) }}" class="underline">{{ $p->next->client->name }}</a></p>@endif
                                    @if ($p->previous?->client)<p class="mt-1">Earlier at <a href="{{ route('admin.clients.show', $p->previous->client) }}" class="underline">{{ $p->previous->client->name }}</a></p>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-6 text-slate-500">No people yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-4 border-t lg:border-t-0 lg:border-l border-slate-100 space-y-3 text-sm">
                <div>
                    <p class="text-xs font-medium text-slate-900">Installed equipment</p>
                    @forelse ($client->installedItems() as $item)<p class="text-slate-700">{{ $item }}</p>@empty<p class="text-slate-500">Nothing recorded</p>@endforelse
                </div>
                <div><p class="text-xs font-medium text-slate-900">Consumables</p><p class="text-slate-700 whitespace-pre-line">{{ $client->consumables ?: '—' }}</p></div>
                <div><p class="text-xs font-medium text-slate-900">Notes for the AI team</p><p class="text-slate-700 whitespace-pre-line">{{ $client->notes ?: '—' }}</p></div>
            </div>
        </div>
    </section>
</div>
@endsection
