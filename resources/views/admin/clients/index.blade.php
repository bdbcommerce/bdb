@extends('admin.layout')
@section('title', 'Client portraits')
@section('heading', 'AI Sales & Support Team — Client portraits')

@php use App\Models\Client; use App\Models\ClientPerson; @endphp

@section('content')
<div class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-sm text-slate-600 max-w-3xl">
            One card per company, with every person we talk to there: position, department, their part in decisions, how to talk to them and a status.
            When a person linked to a Telegram customer writes, the AI team reads the portrait first (who decides, what is installed, what runs out).
            “Do not contact” stops the AI from writing to them first.
        </p>
        <a href="{{ route('admin.clients.create') }}" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700">Add company</a>
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

    @if ($unassigned)
        <p class="text-sm text-slate-600">{{ $unassigned }} {{ \Illuminate\Support\Str::plural('person', $unassigned) }} without a company (their company card was deleted).</p>
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
@endsection
