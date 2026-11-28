{{-- The two lists of the Product catalog: the items loaded from 1C (Products) and the price list. $active: items | prices --}}
@php
    $catalogTabs = [
        'items' => ['Items from 1C', route('admin.products.index'), \App\Models\OnecItem::count()],
        'prices' => ['Price list', route('admin.catalog.index', ['list' => 'prices']), \App\Models\CatalogProduct::available()->count()],
    ];
@endphp
<nav class="flex flex-wrap gap-1 border-b border-slate-200" aria-label="Product catalog">
    @foreach($catalogTabs as $key => [$label, $url, $n])
        <a href="{{ $url }}" @if($active === $key) aria-current="page" @endif
           class="-mb-px px-4 py-2 text-sm font-medium border-b-2 {{ $active === $key ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-900' }}">{{ $label }} <span class="text-xs font-normal text-slate-400">{{ $n }}</span></a>
    @endforeach
</nav>
