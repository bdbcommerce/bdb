@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('Industries') . ' — BDB Auto ID')
@section('description', __('seo.industries_desc'))

@section('content')

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    <div class="mb-12 max-w-2xl">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">BDB Auto ID</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">{{ __('Industries') }}</h1>
    </div>

    @if($industries->isEmpty())
        <p class="text-gray-500">{{ __('No industries yet.') }}</p>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($industries as $industry)
                <div class="bg-white border border-gray-200 rounded-xl p-7 hover:border-red-200 transition-colors group">
                    <div class="w-10 h-10 mb-5 text-gray-300 group-hover:text-red-500 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-gray-900 mb-3">
                        {{ $industry->getTranslation('name', $locale) }}
                    </h2>
                    @if($industry->getTranslation('description', $locale, false))
                        <p class="text-sm text-gray-500 leading-relaxed">
                            {{ $industry->getTranslation('description', $locale) }}
                        </p>
                    @endif
                    @if($industry->customers_count > 0)
                        <div class="mt-5 pt-5 border-t border-gray-100">
                            <a href="{{ route('site.customers', $locale) }}#industry-{{ $industry->id }}"
                               class="text-xs font-semibold text-red-600 hover:text-red-700 transition-colors">
                                {{ __('Customers') }} →
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</div>

@endsection
