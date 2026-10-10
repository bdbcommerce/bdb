@extends('site.layout')

@php
    $locale = app()->getLocale();
    // Only offer filters for industries that actually have customers listed.
    $usedIndustryIds = $customers->pluck('industry_id')->filter()->unique();
    $filterIndustries = $industries->whereIn('id', $usedIndustryIds)->values();
@endphp

@section('title', __('Customers'))
@section('description', __('seo.customers_desc'))

@section('content')

@include('site.partials.page-header', [
    'title'   => __('site.customers.title'),
    'lead'    => __('site.customers.lead'),
    'eyebrow' => __('site.industries.eyebrow'),
    'crumbs'  => [['label' => __('Customers'), 'url' => null]],
])

<section class="bg-white" aria-label="{{ __('Customers') }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        @if($customers->isEmpty())
            @include('site.partials.empty-state', [
                'icon'  => 'users',
                'title' => __('site.customers.empty_title'),
                'text'  => __('site.customers.empty_text'),
            ])
        @else
            <div x-data="{
                    active: 'all',
                    init() {
                        const m = location.hash.match(/^#industry-(\d+)$/);
                        if (m && this.$refs['f' + m[1]]) this.active = m[1];
                    }
                 }">
                @if($filterIndustries->count() > 1)
                    <div role="group" aria-label="{{ __('site.customers.filter') }}"
                         class="flex gap-2 overflow-x-auto scrollbar-none -mx-4 px-4 sm:mx-0 sm:px-0 sm:flex-wrap pb-1 mb-10">
                        <button type="button" aria-pressed="true" :aria-pressed="(active === 'all').toString()"
                                @click="active = 'all'; history.replaceState(null, '', location.pathname)"
                                class="tab tab-inactive aria-pressed:border-red-600 aria-pressed:bg-red-600 aria-pressed:text-white">
                            {{ __('All') }}
                            <span class="ml-1 tabular-nums opacity-70">{{ $customers->count() }}</span>
                        </button>
                        @foreach($filterIndustries as $industry)
                            <button type="button" aria-pressed="false" x-ref="f{{ $industry->id }}"
                                    :aria-pressed="(active === '{{ $industry->id }}').toString()"
                                    @click="active = '{{ $industry->id }}'; history.replaceState(null, '', '#industry-{{ $industry->id }}')"
                                    class="tab tab-inactive aria-pressed:border-red-600 aria-pressed:bg-red-600 aria-pressed:text-white">
                                {{ $industry->getTranslation('name', $locale) }}
                                <span class="ml-1 tabular-nums opacity-70">{{ $customers->where('industry_id', $industry->id)->count() }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4" aria-live="polite">
                    @foreach($customers as $customer)
                        <li x-show="active === 'all' || active === '{{ $customer->industry_id }}'"
                            x-transition.opacity
                            class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-5 transition-colors hover:border-red-200">
                            <div class="flex h-20 items-center justify-center rounded-xl bg-gray-50 px-4">
                                @if($customer->logo)
                                    <img src="{{ Storage::url($customer->logo) }}" alt="{{ $customer->name }}"
                                         loading="lazy" decoding="async"
                                         class="max-h-11 max-w-full object-contain grayscale opacity-70 transition duration-300 group-hover:grayscale-0 group-hover:opacity-100">
                                @else
                                    @include('site.partials.icon', ['name' => 'building-office', 'class' => 'size-8 text-gray-300'])
                                @endif
                            </div>
                            <h2 class="mt-4 font-semibold text-gray-900">{{ $customer->name }}</h2>
                            @if($customer->industry)
                                <p class="mt-1 text-xs font-medium uppercase tracking-wide text-gray-500">{{ $customer->industry->getTranslation('name', $locale) }}</p>
                            @endif
                            @if($customer->getTranslation('description', $locale, false))
                                <p class="mt-3 text-sm text-gray-600 leading-relaxed line-clamp-3">{{ $customer->getTranslation('description', $locale) }}</p>
                            @endif
                            @if($customer->website)
                                <a href="{{ $customer->website }}" target="_blank" rel="noopener noreferrer nofollow"
                                   class="mt-auto pt-4 inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">
                                    {{ __('site.brands.website') }}
                                    @include('site.partials.icon', ['name' => 'arrow-up-right', 'class' => 'size-3.5'])
                                    <span class="sr-only">({{ __('site.a11y.new_tab') }})</span>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</section>

@include('site.partials.cta-band', ['title' => __('site.customers.cta_title')])

@endsection
