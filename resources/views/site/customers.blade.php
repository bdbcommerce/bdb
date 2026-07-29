@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('Customers') . ' — BDB Auto ID')
@section('description', __('seo.customers_desc'))

@section('content')

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    <div class="mb-12 max-w-2xl">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">BDB Auto ID</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">{{ __('Customers') }}</h1>
    </div>

    @if($customers->isEmpty())
        <p class="text-gray-500">{{ __('No customers yet.') }}</p>
    @else

        {{-- Industry filter tabs (Alpine.js) --}}
        <div x-data="{ active: 'all' }" class="mb-10">
            <div class="flex flex-wrap gap-2 mb-10">
                <button @click="active = 'all'"
                        :class="active === 'all' ? 'bg-red-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-400'"
                        class="px-4 py-1.5 text-sm font-medium rounded-full transition-colors">
                    {{ __('All') }}
                </button>
                @foreach($industries as $industry)
                    <button @click="active = '{{ $industry->id }}'"
                            :class="active === '{{ $industry->id }}' ? 'bg-red-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-400'"
                            class="px-4 py-1.5 text-sm font-medium rounded-full transition-colors">
                        {{ $industry->getTranslation('name', $locale) }}
                    </button>
                @endforeach
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                @foreach($customers as $customer)
                    <div x-show="active === 'all' || active === '{{ $customer->industry_id }}'"
                         x-transition
                         id="industry-{{ $customer->industry_id }}"
                         class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col items-center gap-3 hover:border-red-200 transition-colors group">
                        @if($customer->logo)
                            <img src="{{ Storage::url($customer->logo) }}"
                                 alt="{{ $customer->name }}"
                                 loading="lazy" decoding="async"
                                 class="h-10 object-contain grayscale opacity-60 group-hover:opacity-100 group-hover:grayscale-0 transition-all duration-300">
                        @endif
                        @if($customer->name)
                            <p class="text-xs font-semibold text-gray-500 text-center group-hover:text-gray-900 transition-colors">
                                {{ $customer->name }}
                            </p>
                        @endif
                        @if($customer->getTranslation('description', $locale, false))
                            <p class="text-xs text-gray-400 text-center line-clamp-2 leading-relaxed hidden group-hover:block">
                                {{ $customer->getTranslation('description', $locale) }}
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    @endif

</div>

@endsection
