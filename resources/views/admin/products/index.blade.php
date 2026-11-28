@extends('admin.layout')
@section('title', 'Product catalog')
@section('heading', 'AI Sales & Support Team — Product catalog')

@section('content')
@php
    $categories = \App\Models\OnecItem::categories();
    $tabs = ['' => ['All', $counts['all']], 'empty' => ['No information', $counts['empty']], 'ai' => ['AI draft — check', $counts['ai']], 'checked' => ['Checked', $counts['checked']], 'hidden' => ['Hidden from the AI', $counts['hidden']]];
    $pill = 'text-[11px] font-medium rounded-full px-2 py-0.5';
@endphp
<div class="space-y-4">
    @include('admin.partials.catalog-tabs', ['active' => 'items'])
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-xs text-slate-500 max-w-2xl">The items loaded from 1C. Name, code, unit and barcode come from 1C and are replaced by every load. The information for the AI team (brand, model, category, description, specifications…) is ours: type it, or let the AI draft it from the 1C texts and then check it. A 1C load never changes it.</p>
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <form method="POST" action="{{ route('admin.companies.import-items') }}" data-onec-import="items">@csrf
                <button class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50">Load items from 1C</button>
            </form>
            @if($counts['to_describe'])
                <form method="POST" action="{{ route('admin.products.ai-fill-batch') }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'The AI is reading… (a few minutes)';">@csrf
                    <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-60">Fill the next {{ min(\App\Http\Controllers\Admin\ProductController::BATCH, $counts['to_describe']) }} with AI</button>
                </form>
            @endif
        </div>
    </div>

    <nav class="flex flex-wrap gap-2" aria-label="Filter by information">
        @foreach($tabs as $key => [$label, $n])
            <a href="{{ route('admin.products.index', array_filter(['info' => $key, 'q' => $search, 'category' => $category])) }}"
               @if($status === $key) aria-current="true" @endif
               class="text-xs font-medium rounded-full px-3 py-1.5 border {{ $status === $key ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' }}">{{ $label }} <span class="{{ $status === $key ? 'text-slate-300' : 'text-slate-400' }}">{{ $n }}</span></a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('admin.products.index') }}" class="flex flex-wrap gap-2" role="search">
        @if($status !== '')<input type="hidden" name="info" value="{{ $status }}">@endif
        <label for="product-search" class="sr-only">Search products</label>
        <input id="product-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="Name, 1C code, barcode, brand, model"
               class="w-full max-w-md border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
        <label for="product-category" class="sr-only">Category</label>
        <select id="product-category" name="category" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="">Every category</option>
            <option value="none" @selected($category === 'none')>No category yet</option>
            @foreach($categories as $key => $label)<option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>@endforeach
        </select>
        <button type="submit" class="border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50">Search</button>
        @if($search !== '' || $category !== '')
            <a href="{{ route('admin.products.index', array_filter(['info' => $status])) }}" class="self-center text-xs text-slate-500 underline hover:text-slate-900">Clear</a>
        @endif
    </form>

    <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
        @if($products->isEmpty())
            <div class="px-6 py-16 text-center">
                <p class="text-sm font-medium text-slate-500">{{ $counts['all'] ? 'No products match' : 'No products yet' }}</p>
                <p class="text-xs text-slate-400 mt-1">{{ $counts['all'] ? 'Try another search or filter.' : 'Press “Load items from 1C” to bring the list in.' }}</p>
            </div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($products as $product)
                    <a href="{{ route('admin.products.edit', $product) }}" class="flex flex-wrap sm:flex-nowrap items-center gap-x-4 gap-y-1.5 px-4 py-3 hover:bg-slate-50">
                        <span class="w-14 shrink-0 text-xs font-mono text-slate-500">{{ $product->code }}</span>
                        <div class="min-w-0 flex-1 basis-60">
                            <p class="text-sm font-medium text-slate-900 truncate">{{ $product->name }}</p>
                            <p class="text-xs text-slate-500 truncate">{{ $product->short_name ?: $product->full_name ?: '—' }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 shrink-0 max-w-full">
                            @if($product->brand || $product->model)
                                <span class="text-xs text-slate-600 truncate max-w-40" title="{{ trim($product->brand.' '.$product->model) }}">{{ trim($product->brand.' '.$product->model) }}</span>
                            @endif
                            @if($product->categoryLabel())
                                <span class="{{ $pill }} border border-slate-200 text-slate-600">{{ $product->categoryLabel() }}</span>
                            @endif
                            @if($product->info_status === 'checked')
                                <span class="{{ $pill }} bg-slate-900 text-white">Checked</span>
                            @elseif($product->info_status === 'ai')
                                <span class="{{ $pill }} border border-slate-400 text-slate-700">AI draft</span>
                            @else
                                <span class="{{ $pill }} border border-dashed border-slate-300 text-slate-400">No information</span>
                            @endif
                            @unless($product->ai_use)
                                <span class="{{ $pill }} border border-slate-400 text-slate-700">Hidden from the AI</span>
                            @endunless
                            <span class="text-xs text-slate-400 w-10 text-right">{{ $product->unit ?: '' }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    <div>{{ $products->links() }}</div>
    @if($counts['parked'])
        <p class="text-xs text-slate-500">{{ $counts['parked'] }} {{ Str::plural('product', $counts['parked']) }} the AI could not read {{ $counts['parked'] === 1 ? 'is' : 'are' }} set aside for {{ \App\Http\Controllers\Admin\ProductController::FAILED_HOURS }} hours. Open one and press “Fill empty fields with AI” to try it sooner, or type the information yourself.</p>
    @endif
    @if($lastLoad)
        <p class="text-xs text-slate-400">Last change from 1C or staff: {{ \Illuminate\Support\Carbon::parse($lastLoad)->diffForHumans() }}.</p>
    @endif
</div>
@include('admin.partials.onec-import')
@endsection
