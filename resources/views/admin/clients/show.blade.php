@extends('admin.layout')
@section('title', $client->name)
@section('heading', 'Company — '.$client->name)

@php use App\Models\CatalogProduct; use App\Models\Client; use App\Models\ClientPerson; use App\Models\CompanyItem; use App\Models\CompanyLink; @endphp

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
                <p class="text-xs text-slate-500">{{ collect([$client->address, $client->director ? 'Director: '.$client->director : null])->filter()->implode(' · ') }}</p>
                <p class="text-xs text-slate-500 mt-1">
                    <span class="rounded-full border border-slate-300 px-2 py-0.5">Price type: {{ ['dealer' => 'Dealer', 'partner' => 'Partner'][$client->price_type] ?? 'End user' }}</span>
                    <span class="ml-1">Data: {{ Client::SOURCES[$client->source] ?? $client->source }}{{ $client->looked_up_at ? ', '.$client->looked_up_at->format('d.m.Y H:i') : '' }}{{ $client->onec_code ? ' · 1C code '.$client->onec_code : '' }}{{ $client->contract ? ' · '.(Client::CONTRACTS[$client->contract] ?? $client->contract) : '' }}</span>
                    @if ($client->full_name && $client->full_name !== $client->name)<span class="block mt-0.5">{{ $client->full_name }}</span>@endif
                </p>
                <p class="text-xs text-slate-500">{{ $client->since ? 'Client since '.$client->since : '' }}{{ $client->since && $client->owner ? ' · ' : '' }}{{ $client->owner ? 'Looks after them: '.$client->owner : '' }}</p>
            </div>
            @if ($client->inn)
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.companies.refresh', $client) }}">@csrf<button class="text-xs border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-50">Update from 1C</button></form>
                    @unless ($client->onec_code)
                        <form method="POST" action="{{ route('admin.companies.create-in-1c', $client) }}" onsubmit="return confirm('Create this company as a client in 1C?')">@csrf<button class="text-xs border border-slate-900 rounded-lg px-3 py-1.5 hover:bg-slate-50" title="Adds a client card in 1C (clients/newClient)">Create in 1C</button></form>
                    @endunless
                    <form method="POST" action="{{ route('admin.companies.purchases', $client) }}">@csrf<button class="text-xs border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-50" title="Sales-plan items they bought move to usually orders">Purchases from 1C</button></form>
                </div>
            @endif
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

    {{-- Who works with this company in Telegram: contacts and groups. --}}
    <section class="bg-white border border-slate-200 rounded-xl">
        <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900">Telegram contacts and groups</h2>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($client->links()->with('telegramUser')->latest()->get() as $link)
                <li class="px-5 py-2.5 flex flex-wrap items-center gap-3">
                    <span class="flex-1 min-w-0">
                        @if ($link->telegramUser)
                            <a href="{{ route('admin.telegram-users.show', $link->telegramUser) }}" class="font-medium text-slate-900 underline">{{ $link->telegramUser->displayName() ?? 'Telegram '.$link->telegramUser->telegram_id }}</a>
                        @else
                            <span class="font-medium text-slate-900">Group: {{ $link->groupTitle() ?? $link->group_chat_id }}</span>
                        @endif
                        <span class="text-xs text-slate-500"> · {{ CompanyLink::BY[$link->linked_by] ?? $link->linked_by }}{{ $link->linked_by_name ? ' ('.$link->linked_by_name.')' : '' }}, {{ $link->created_at->format('d.m.Y') }}</span>
                    </span>
                    @if (! $link->byManager())
                        <form method="POST" action="{{ route('admin.companies.confirm-link', $link) }}">@csrf<button class="text-xs border border-slate-900 rounded-lg px-2.5 py-1 hover:bg-slate-50" title="The company's price type then applies to this contact">Confirm</button></form>
                    @endif
                    <form method="POST" action="{{ route('admin.companies.unlink', $link) }}" onsubmit="return confirm('Unlink?')">@csrf @method('DELETE')<button class="text-xs text-slate-500 underline hover:text-red-700">Unlink</button></form>
                </li>
            @empty
                <li class="px-5 py-4 text-slate-500">Nobody yet. The AI links a contact when they send this company's INN.</li>
            @endforelse
        </ul>
        @php $groups = \App\Models\TelegramChat::options('groups'); @endphp
        <form method="POST" action="{{ route('admin.companies.link', $client) }}" class="px-5 py-3 border-t border-slate-100 flex flex-wrap items-center gap-2">
            @csrf
            <label for="link-contact" class="sr-only">Contact</label>
            <input id="link-contact" name="contact" maxlength="64" placeholder="Telegram ID, @username or phone" value="{{ old('contact') }}" class="w-64 rounded-lg border-slate-300 text-sm">
            @if ($groups->isNotEmpty())
                <span class="text-xs text-slate-400">or</span>
                <label for="link-group" class="sr-only">Group</label>
                <select id="link-group" name="group_chat_id" class="rounded-lg border-slate-300 text-sm">
                    <option value="">a Telegram group…</option>
                    @foreach ($groups as $gid => $gname)<option value="{{ $gid }}">{{ $gname }}</option>@endforeach
                </select>
            @endif
            <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-3 py-1.5 hover:bg-slate-700">Link</button>
        </form>
    </section>

    {{-- Items 1C keeps for this company (made for them, e.g. their labels). --}}
    @php $made = $client->onec_id ? \App\Models\OnecItem::where('company_onec_id', $client->onec_id)->orderBy('name')->limit(200)->get() : collect(); @endphp
    @if ($made->isNotEmpty())
        <section class="bg-white border border-slate-200 rounded-xl">
            <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-semibold text-slate-900">Their items in 1C <span class="font-normal text-slate-500">({{ $made->count() }})</span></h2>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($made as $i)
                    <li class="px-5 py-2 flex flex-wrap items-center gap-3">
                        <span class="text-xs text-slate-400 w-10">{{ $i->code }}</span>
                        <span class="flex-1">{{ $i->name }}@if ($i->full_name)<span class="block text-xs text-slate-500">{{ $i->full_name }}</span>@endif</span>
                        @unless ($client->items->where('kind', 'item1c')->where('value', (string) $i->code)->isNotEmpty())
                            <form method="POST" action="{{ route('admin.companies.add-item', $client) }}">@csrf<input type="hidden" name="list" value="usual"><input type="hidden" name="kind" value="item1c"><input type="hidden" name="value" value="{{ $i->code }}"><button class="text-xs border border-slate-300 rounded-lg px-2 py-0.5 hover:bg-slate-50">Add to usually orders</button></form>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- What they usually order, and what we plan to sell them (the AI reads both). --}}
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (CompanyItem::LISTS as $list => $title)
            <section class="bg-white border border-slate-200 rounded-xl">
                <div class="px-5 py-3 border-b border-slate-100">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                    <p class="text-xs text-slate-500">{{ $list === 'usual' ? 'The AI may suggest these again ("the same as usual?").' : 'The AI offers at most one of these per conversation, when it fits. Bought (a confirmed request or 1C purchase) → moves to usually orders.' }}</p>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($client->items->where('list', $list) as $item)
                        <li class="px-5 py-2 flex items-center gap-3">
                            <span class="text-[11px] rounded border border-slate-300 px-1.5 text-slate-500">{{ CompanyItem::KINDS[$item->kind] }}</span>
                            <span class="flex-1">{{ $item->label() }}@if ($item->moved_at)<span class="block text-xs text-slate-400">From the sales plan {{ $item->moved_at->format('d.m.Y') }} · {{ $item->moved_by }}</span>@endif</span>
                            @if ($list === 'plan')
                                <form method="POST" action="{{ route('admin.companies.move-item', $item) }}">@csrf<button class="text-xs border border-slate-300 rounded-lg px-2 py-0.5 hover:bg-slate-50">Ordered → usual</button></form>
                            @endif
                            <form method="POST" action="{{ route('admin.companies.remove-item', $item) }}">@csrf @method('DELETE')<button class="text-xs text-slate-500 underline hover:text-red-700" aria-label="Remove {{ $item->label() }}">Remove</button></form>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-slate-500">Empty.</li>
                    @endforelse
                </ul>
                <form method="POST" action="{{ route('admin.companies.add-item', $client) }}" class="px-5 py-3 border-t border-slate-100 flex flex-wrap items-center gap-2" x-data="{ kind: 'category' }">
                    @csrf
                    <input type="hidden" name="list" value="{{ $list }}">
                    <label class="sr-only" for="kind-{{ $list }}">Kind</label>
                    <select id="kind-{{ $list }}" name="kind" x-model="kind" class="rounded-lg border-slate-300 text-sm">
                        @foreach (CompanyItem::KINDS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <label class="sr-only" for="value-{{ $list }}">Value</label>
                    <select x-show="kind === 'category'" :disabled="kind !== 'category'" name="value" class="rounded-lg border-slate-300 text-sm">
                        @foreach (CatalogProduct::CATEGORIES as $k => [$label])<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <input x-show="kind !== 'category'" x-cloak :disabled="kind === 'category'" id="value-{{ $list }}" name="value" maxlength="64" :list="{ sku: 'sku-list', item1c: 'item1c-list', brand: 'brand-list' }[kind]" :placeholder="{ sku: 'SKU or model…', item1c: '1C code or name…', brand: 'Brand…' }[kind]" class="w-48 rounded-lg border-slate-300 text-sm">
                    <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-3 py-1.5 hover:bg-slate-700">Add</button>
                </form>
            </section>
        @endforeach
    </div>
    <datalist id="sku-list">@foreach (CatalogProduct::available()->orderBy('sku')->limit(2000)->get(['sku', 'brand', 'model']) as $p)<option value="{{ $p->sku }}">{{ trim($p->brand.' '.$p->model) }}</option>@endforeach</datalist>
    <datalist id="item1c-list">@foreach (\App\Models\OnecItem::orderBy('name')->limit(3000)->get(['code', 'name']) as $i)<option value="{{ $i->code }}">{{ $i->name }}</option>@endforeach</datalist>
    <datalist id="brand-list">@foreach (CatalogProduct::whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand') as $b)<option value="{{ $b }}">@endforeach</datalist>
</div>
@endsection
