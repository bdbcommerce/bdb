@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('Brands') . ' — BDB Auto ID')
@section('description', __('seo.brands_desc'))

@section('content')

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    <div class="mb-12 max-w-2xl">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">BDB Auto ID</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">{{ __('Brands') }}</h1>
    </div>

    @if($brands->isEmpty())
        <p class="text-gray-500">{{ __('No brands yet.') }}</p>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-px bg-gray-200 border border-gray-200 rounded-xl overflow-hidden">
            @foreach($brands as $brand)
                <div class="bg-white p-8 flex flex-col items-center justify-center gap-4 group hover:bg-gray-50 transition-colors">
                    @if($brand->logo)
                        <img src="{{ Storage::url($brand->logo) }}"
                             alt="{{ $brand->getTranslation('name', $locale) }}"
                             loading="lazy" decoding="async"
                             class="h-12 object-contain grayscale opacity-60 group-hover:opacity-100 group-hover:grayscale-0 transition-all duration-300">
                    @endif
                    <p class="text-xs font-semibold text-gray-400 group-hover:text-gray-900 text-center transition-colors">
                        {{ $brand->getTranslation('name', $locale) }}
                    </p>
                    @if($brand->getTranslation('description', $locale, false))
                        <p class="text-xs text-gray-400 text-center line-clamp-2 leading-relaxed hidden group-hover:block">
                            {{ $brand->getTranslation('description', $locale) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</div>

@endsection
