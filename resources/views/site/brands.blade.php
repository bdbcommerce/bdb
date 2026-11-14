@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('Brands'))
@section('description', __('seo.brands_desc'))

@section('content')

@include('site.partials.page-header', [
    'title'   => __('site.brands.title'),
    'lead'    => __('site.brands.lead'),
    'eyebrow' => __('site.brands.eyebrow'),
    'crumbs'  => [['label' => __('Brands'), 'url' => null]],
])

<section class="bg-white" aria-label="{{ __('Brands') }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        @if($brands->isEmpty())
            @include('site.partials.empty-state', [
                'icon'  => 'cube',
                'title' => __('site.brands.empty_title'),
                'text'  => __('site.brands.empty_text'),
            ])
        @else
            <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" x-data>
                @foreach($brands as $brand)
                    @php
                        $name = $brand->getTranslation('name', $locale);
                        $desc = $brand->getTranslation('description', $locale, false);
                    @endphp
                    <li class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 transition-colors hover:border-brand-200">
                        {{-- White logo on a dark tile; real colours on a light tile on hover / focus. --}}
                        <div class="flex h-20 items-center justify-center rounded-xl bg-gray-900 px-6 transition-colors duration-300 group-hover:bg-gray-50 group-focus-within:bg-gray-50">
                            @if($brand->logo)
                                <img src="{{ Storage::url($brand->logo) }}" alt="{{ $name }}"
                                     loading="lazy" decoding="async"
                                     class="max-h-12 max-w-full object-contain brightness-0 invert transition duration-300 group-hover:brightness-100 group-hover:invert-0 group-focus-within:brightness-100 group-focus-within:invert-0">
                            @else
                                <span class="text-lg font-semibold tracking-wide text-white transition-colors duration-300 group-hover:text-gray-900 group-focus-within:text-gray-900">{{ $name }}</span>
                            @endif
                        </div>
                        <h2 class="mt-5 text-base font-semibold text-gray-900">{{ $name }}</h2>
                        @if($desc)
                            <p class="mt-2 text-sm text-gray-600 leading-relaxed line-clamp-4">{{ $desc }}</p>
                        @endif
                        <div class="mt-auto pt-5 flex flex-wrap items-center gap-x-5 gap-y-2">
                            @if($shop = $brand->shopUrl('brand_card'))
                                <a href="{{ $shop }}" target="_blank" rel="noopener"
                                   title="{{ __('site.brands.shop_title', ['brand' => $name]) }}"
                                   class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:text-brand-700 transition-colors rounded">
                                    @include('site.partials.icon', ['name' => 'shopping-cart', 'class' => 'size-4'])
                                    {{ __('site.brands.shop') }}
                                    <span class="sr-only">{{ $name }} {{ __('site.shop.new_tab') }}</span>
                                </a>
                            @endif
                            <button type="button"
                                    @click="$dispatch('belgi-open', { message: @js(__('site.brands.ask_about', ['brand' => $name])) })"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-brand-600 transition-colors rounded">
                                @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4'])
                                {{ __('site.services.ask') }}
                            </button>
                            @if($brand->website)
                                <a href="{{ $brand->website }}" target="_blank" rel="noopener noreferrer nofollow"
                                   class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">
                                    {{ __('site.brands.website') }}
                                    @include('site.partials.icon', ['name' => 'arrow-up-right', 'class' => 'size-3.5'])
                                    <span class="sr-only">({{ __('site.a11y.new_tab') }})</span>
                                </a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

@include('site.partials.shop-band', ['placement' => 'brands_block'])

@include('site.partials.cta-band')

@endsection
