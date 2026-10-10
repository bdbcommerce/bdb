@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('Industries'))
@section('description', __('seo.industries_desc'))

@section('content')

@include('site.partials.page-header', [
    'title'   => __('site.industries.title'),
    'lead'    => __('site.industries.lead'),
    'eyebrow' => __('site.industries.eyebrow'),
    'crumbs'  => [['label' => __('Industries'), 'url' => null]],
])

<section class="bg-white" aria-label="{{ __('Industries') }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        @if($industries->isEmpty())
            @include('site.partials.empty-state', [
                'icon'  => 'building-office',
                'title' => __('site.industries.empty_title'),
                'text'  => __('site.industries.empty_text'),
            ])
        @else
            <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($industries as $industry)
                    <li id="industry-{{ $industry->id }}" class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 md:p-7 transition-colors hover:border-red-200">
                        <span class="icon-tile size-12 group-hover:border-red-200 group-hover:text-red-600 transition-colors">
                            @include('site.partials.icon', ['name' => $industry->icon, 'class' => 'size-6'])
                        </span>
                        <h2 class="mt-5 text-lg font-semibold text-gray-900">{{ $industry->getTranslation('name', $locale) }}</h2>
                        @if($industry->getTranslation('description', $locale, false))
                            <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ $industry->getTranslation('description', $locale) }}</p>
                        @endif
                        @if($industry->customers_count > 0)
                            <div class="mt-auto pt-6">
                                <a href="{{ route('site.customers', $locale) }}#industry-{{ $industry->id }}" class="link-arrow">
                                    {{ __('site.industries.see_customers') }}
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 tabular-nums">{{ $industry->customers_count }}</span>
                                </a>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

@include('site.partials.cta-band')

@endsection
