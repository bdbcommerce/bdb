@extends('admin.layout')
@section('title', 'Product catalog')
@section('heading', 'AI Sales & Support Team — Product catalog')

@php
    use App\Models\CatalogProduct;
    use App\Models\CatalogSetting;
    $card = 'bg-white border border-slate-200 rounded-xl p-6 space-y-4';
    $h2 = 'text-xs font-semibold text-slate-500 uppercase tracking-wide';
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $code = 'block w-full overflow-x-auto whitespace-pre rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 font-mono text-xs text-slate-800';
    $token = session('catalog_token');
    $badge = ['ok' => 'bg-slate-900 text-white border-slate-900', 'refused' => 'bg-amber-50 text-amber-800 border-amber-200', 'failed' => 'bg-red-50 text-red-800 border-red-200'];
    $fresh = $settings->isFresh();
    $money = fn (?int $v) => $v === null ? '—' : number_format($v, 0, '.', ' ');
@endphp

@section('content')
<div class="w-full space-y-6">
    @include('admin.partials.catalog-tabs', ['active' => 'prices'])
    @if(session('error'))
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="{{ $card }}">
        <div>
            <h2 class="{{ $h2 }}">What it is</h2>
            <p class="text-sm text-slate-600 mt-2 max-w-3xl">
                Models, parameters, prices (end user / dealer / partner) and stock from 1C, plus the list of partners and dealers.
                Every load is the full list: products missing from it are hidden from the AI. The format for the 1C developer is in
                <code class="text-xs">docs/1c-catalog-http-service.md</code>.
            </p>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-[11rem_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-slate-500">Source</dt>
            <dd class="text-slate-800">{{ CatalogSetting::SOURCES[$settings->source] ?? $settings->source }}</dd>
            <dt class="text-slate-500">Last good load</dt>
            <dd class="text-slate-800">
                @if($settings->last_success_at)
                    {{ $settings->last_success_at->format('d.m.Y H:i') }} ({{ $settings->last_success_at->diffForHumans() }})
                    <span class="ml-1 text-[11px] font-medium rounded-full px-2 py-0.5 border {{ $fresh ? 'border-slate-300 text-slate-600' : 'bg-amber-50 text-amber-800 border-amber-200' }}">{{ $fresh ? 'Fresh' : 'Stale — the AI quotes no prices or stock' }}</span>
                @else
                    Never
                @endif
            </dd>
            @if($settings->last_error)
                <dt class="text-slate-500">Last problem</dt>
                <dd class="text-red-700 break-words">{{ $settings->last_error }}</dd>
            @endif
            <dt class="text-slate-500">Products for the AI</dt>
            <dd class="text-slate-800">{{ $counts->sum() }} @if($hiddenCount) · <a href="{{ route('admin.catalog.index', ['hidden' => 1]) }}" class="underline text-slate-500">{{ $hiddenCount }} hidden</a>@endif</dd>
            <dt class="text-slate-500">Partner / dealer phones</dt>
            <dd class="text-slate-800">{{ $clientCounts['partner'] ?? 0 }} partner · {{ $clientCounts['dealer'] ?? 0 }} dealer</dd>
        </dl>
    </div>

    @if($token)
        <div role="alert" class="rounded-xl border-2 border-slate-900 bg-white p-5 space-y-2">
            <p class="text-sm font-semibold text-slate-900">New push token — copy it into 1C now, it will not be shown again</p>
            <code class="{{ $code }}">{{ $token }}</code>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.catalog.update') }}" class="{{ $card }}" x-data="{ source: @js(old('source', $settings->source)) }">
        @csrf @method('PUT')
        <h2 class="{{ $h2 }}">Source</h2>
        <fieldset class="space-y-2">
            <legend class="sr-only">Catalog source</legend>
            @foreach(CatalogSetting::SOURCES as $key => $label)
                <label class="flex items-center gap-2 text-sm text-slate-800">
                    <input type="radio" name="source" value="{{ $key }}" x-model="source" class="border-slate-300 text-slate-900 focus:ring-slate-400"> {{ $label }}
                </label>
            @endforeach
        </fieldset>

        <div x-show="source === 'push'" class="space-y-2 text-sm">
            <p class="text-slate-600">1C sends <code class="text-xs">POST</code> to this address with the header <code class="text-xs">Authorization: Bearer &lt;token&gt;</code>:</p>
            <code class="{{ $code }}">{{ $endpoint }}</code>
            <p class="text-xs text-slate-500">
                @if($settings->token_hint) Token ends with <code>…{{ $settings->token_hint }}</code>. @else No token yet — saving in push mode creates one. @endif
            </p>
        </div>

        <div x-show="source === 'pull'" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <label for="pull_url" class="block text-sm font-medium text-slate-700 mb-1">1C HTTP service address</label>
                <input id="pull_url" name="pull_url" type="url" maxlength="500" value="{{ old('pull_url', $settings->pull_url) }}" placeholder="https://1c.example.uz/base/hs/bdb/catalog" class="{{ $input }} font-mono">
                <p class="text-xs text-slate-500 mt-1">HTTPS only. Loaded every hour at :15 (Scheduled tasks → Product catalog).</p>
            </div>
            <div>
                <label for="pull_username" class="block text-sm font-medium text-slate-700 mb-1">1C user</label>
                <input id="pull_username" name="pull_username" maxlength="100" autocomplete="off" value="{{ old('pull_username', $settings->pull_username) }}" class="{{ $input }}">
            </div>
            <div>
                <label for="pull_password" class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                <input id="pull_password" name="pull_password" type="password" maxlength="200" autocomplete="new-password" placeholder="{{ $settings->pull_password ? 'saved — leave empty to keep' : '' }}" class="{{ $input }}">
            </div>
        </div>

        <div class="max-w-xs">
            <label for="max_age_hours" class="block text-sm font-medium text-slate-700 mb-1">Max catalog age, hours</label>
            <input id="max_age_hours" name="max_age_hours" type="number" min="1" max="720" required value="{{ old('max_age_hours', $settings->max_age_hours) }}" class="{{ $input }}">
            <p class="text-xs text-slate-500 mt-1">Older than this: the AI quotes no prices or stock, and admins get an alert.</p>
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">Save</button>
        </div>
    </form>

    <div class="flex flex-wrap gap-3">
        @if($settings->source === 'pull')
            <form method="POST" action="{{ route('admin.catalog.pull') }}">
                @csrf
                <button type="submit" class="text-sm font-medium rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-50">Load now from 1C</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.catalog.token') }}" onsubmit="return confirm('Create a new token? The old one stops working at once.')">
                @csrf
                <button type="submit" class="text-sm font-medium rounded-lg border border-slate-300 px-4 py-2 hover:bg-slate-50">{{ $settings->token_hash ? 'New push token' : 'Create push token' }}</button>
            </form>
        @endif
    </div>

    <section class="bg-white border border-slate-200 rounded-xl overflow-x-auto" aria-labelledby="loads-h">
        <h2 id="loads-h" class="{{ $h2 }} px-5 pt-4">Last loads</h2>
        @if($loads->isEmpty())
            <p class="px-5 py-4 text-sm text-slate-500">No loads yet.</p>
        @else
            <table class="w-full text-sm mt-2">
                <thead class="text-xs text-slate-500 text-left border-b border-slate-100">
                    <tr><th class="px-5 py-2 font-medium">Time</th><th class="px-3 py-2 font-medium">Source</th><th class="px-3 py-2 font-medium">Result</th>
                        <th class="px-3 py-2 font-medium text-right">Accepted</th><th class="px-3 py-2 font-medium text-right">Rejected</th><th class="px-3 py-2 font-medium text-right">Hidden</th><th class="px-3 py-2 font-medium">Details</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 align-top">
                    @foreach($loads as $load)
                        <tr>
                            <td class="px-5 py-2.5 whitespace-nowrap text-slate-700">{{ $load->created_at->format('d.m H:i') }}</td>
                            <td class="px-3 py-2.5 text-slate-600">{{ $load->source }} · {{ $load->trigger }}</td>
                            <td class="px-3 py-2.5"><span class="text-[11px] font-medium rounded-full px-2 py-0.5 border {{ $badge[$load->status] ?? '' }}">{{ $load->status }}</span></td>
                            <td class="px-3 py-2.5 text-right">{{ $load->accepted }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $load->rejected_count }}</td>
                            <td class="px-3 py-2.5 text-right">{{ $load->hidden }}</td>
                            <td class="px-3 py-2.5 text-slate-600 min-w-[16rem]">
                                @if($load->error)<p class="text-red-700 break-words">{{ $load->error }}</p>@endif
                                @if($load->rejected)
                                    <details><summary class="cursor-pointer text-xs underline">Rejected items</summary>
                                        <ul class="mt-1 space-y-0.5 text-xs">
                                            @foreach($load->rejected as $r)
                                                <li><code>{{ isset($r['client']) ? 'client #'.$r['client'] : ($r['sku'] ?? '—') }}</code>: {{ $r['error'] ?? '' }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="space-y-3" aria-labelledby="products-h">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 id="products-h" class="{{ $h2 }}">{{ $hidden ? 'Hidden products' : 'Products' }}</h2>
            <form method="GET" class="flex flex-wrap gap-2">
                <input type="hidden" name="list" value="prices">
                @if($hidden)<input type="hidden" name="hidden" value="1">@endif
                <label for="q" class="sr-only">Search</label>
                <input id="q" name="q" value="{{ $search }}" placeholder="SKU, brand or model" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                <label for="category" class="sr-only">Category</label>
                <select id="category" name="category" class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm">
                    <option value="">All categories</option>
                    @foreach(CatalogProduct::CATEGORIES as $key => [$label])
                        <option value="{{ $key }}" @selected($category === $key)>{{ $label }}@if(!$hidden && isset($counts[$key])) ({{ $counts[$key] }})@endif</option>
                    @endforeach
                </select>
                <button class="text-sm rounded-lg border border-slate-300 px-3 py-1.5 hover:bg-slate-50">Filter</button>
                @if($hidden)<a href="{{ route('admin.catalog.index', ['list' => 'prices']) }}" class="text-sm underline text-slate-600 self-center">Show products for the AI</a>@endif
            </form>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto">
            @if($products->isEmpty())
                <p class="px-5 py-4 text-sm text-slate-500">No products.</p>
            @else
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500 text-left border-b border-slate-100">
                        <tr><th class="px-5 py-2 font-medium">Product</th><th class="px-3 py-2 font-medium">Category</th>
                            <th class="px-3 py-2 font-medium text-right">End user</th><th class="px-3 py-2 font-medium text-right">Dealer</th><th class="px-3 py-2 font-medium text-right">Partner</th>
                            <th class="px-3 py-2 font-medium">Stock</th><th class="px-3 py-2 font-medium">Parameters</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 align-top">
                        @foreach($products as $p)
                            <tr>
                                <td class="px-5 py-2.5">
                                    <p class="font-medium text-slate-900">{{ $p->brand }} {{ $p->model }}</p>
                                    <p class="text-xs text-slate-500"><code>{{ $p->sku }}</code> · {{ $p->displayName() }}</p>
                                    @if(! $p->in_feed)<p class="text-xs text-amber-700">Not in the latest load</p>@elseif(! $p->is_active)<p class="text-xs text-amber-700">Inactive in 1C</p>@endif
                                </td>
                                <td class="px-3 py-2.5 text-slate-600 whitespace-nowrap">{{ CatalogProduct::categoryLabel($p->category) }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ $money($p->prices['end_user'] ?? null) }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ $money($p->prices['dealer'] ?? null) }}</td>
                                <td class="px-3 py-2.5 text-right whitespace-nowrap">{{ $money($p->prices['partner'] ?? null) }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-slate-700">{{ CatalogProduct::STOCK[$p->stock_status] ?? $p->stock_status }}@if($p->stock_qty !== null) · {{ $p->stock_qty }}@endif @if($p->lead_time_days)<span class="text-xs text-slate-500">({{ $p->lead_time_days }} d)</span>@endif</td>
                                <td class="px-3 py-2.5 text-xs text-slate-600 min-w-[14rem]">
                                    @foreach((array) $p->params as $k => $v)
                                        <span class="inline-block mr-2">{{ $k }}: <span class="text-slate-900">{{ is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'yes' : 'no') : $v) }}</span></span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        {{ $products->links() }}
    </section>
</div>
@endsection
