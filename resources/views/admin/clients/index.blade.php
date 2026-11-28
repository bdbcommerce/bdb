@extends('admin.layout')
@section('title', 'Companies')
@section('heading', 'Companies')

@php use App\Models\Client; use App\Models\ClientPerson; @endphp

@section('content')
<div class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-sm text-slate-600 max-w-3xl">
            One card per company, with every person we talk to there: position, department, their part in decisions, how to talk to them and a status.
            When a person linked to a Telegram customer writes, the AI team reads the portrait first (who decides, what is installed, what runs out).
            “Do not contact” stops the AI from writing to them first.
        </p>
        <div class="flex flex-wrap items-start gap-2">
            <form method="POST" action="{{ route('admin.companies.lookup') }}" class="flex gap-2">
                @csrf
                <label for="inn-lookup" class="sr-only">INN</label>
                <input id="inn-lookup" name="inn" inputmode="numeric" maxlength="20" placeholder="INN (9 digits)" value="{{ old('inn') }}" class="w-40 rounded-lg border-slate-300 text-sm">
                <button class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50" title="Our base, then 1C, then the public registry">Find by INN</button>
            </form>
            <a href="{{ route('admin.clients.create') }}" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Add company</a>
        </div>
        <div class="w-full flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <span>1C, read only:</span>
            <form method="POST" action="{{ route('admin.companies.import') }}" data-onec-import="companies">@csrf<button class="border border-slate-300 rounded-lg px-3 py-1.5 bg-white hover:bg-slate-50">Load companies from 1C</button></form>
            <span>Items from 1C ({{ \App\Models\OnecItem::count() }}) are loaded in the <a href="{{ route('admin.products.index') }}" class="underline hover:text-slate-900">Product catalog</a>.</span>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap gap-2" role="search">
        <label for="client-search" class="sr-only">Search companies and people</label>
        <input id="client-search" type="search" name="q" value="{{ $search }}" placeholder="Company, INN or person" class="flex-1 min-w-[12rem] rounded-lg border-slate-300 text-sm">
        <label for="client-status" class="sr-only">Status</label>
        <select id="client-status" name="status" class="rounded-lg border-slate-300 text-sm">
            <option value="">All statuses</option>
            @foreach (Client::STATUSES as $k => $label)<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
        </select>
        <button class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:border-slate-500">Search</button>
    </form>

    @if ($unassigned->isNotEmpty())
        <section class="bg-white border border-slate-200 rounded-xl p-4 text-sm" aria-labelledby="unassigned-h">
            <h2 id="unassigned-h" class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">{{ $unassigned->count() }} {{ \Illuminate\Support\Str::plural('person', $unassigned->count()) }} without a company</h2>
            <ul class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach ($unassigned as $p)
                    <li><a href="{{ route('admin.client-people.edit', $p) }}" class="underline text-slate-800">{{ $p->name }}</a> <span class="text-xs text-slate-500">{{ ClientPerson::STATUSES[$p->status] ?? $p->status }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($clients as $c)
            <a href="{{ route('admin.clients.show', $c) }}" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-slate-400 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-slate-900">{{ $c->name }}</span>
                    <span class="text-xs font-medium rounded-full border border-slate-300 px-2 py-0.5 text-slate-600">{{ Client::STATUSES[$c->status] ?? $c->status }}</span>
                </div>
                <p class="text-sm text-slate-600">{{ collect([$c->industry, $c->city, $c->size])->filter()->implode(' · ') ?: '—' }}</p>
                <p class="text-xs text-slate-500">
                    {{ $c->people->where('status', 'active')->count() }} active {{ \Illuminate\Support\Str::plural('person', $c->people->where('status', 'active')->count()) }}:
                    {{ $c->people->where('status', 'active')->take(4)->map(fn ($p) => $p->name.($p->part ? ' ('.mb_strtolower(ClientPerson::PARTS[$p->part] ?? $p->part).')' : ''))->implode(', ') ?: '—' }}
                </p>
            </a>
        @empty
            <p class="text-sm text-slate-500">No companies{{ $search !== '' || $status ? ' match' : ' yet' }}.</p>
        @endforelse
    </div>
    <div>{{ $clients->links() }}</div>
</div>
@include('admin.partials.onec-import')
@endsection
