@extends('site.layout')

@php
    $locale  = app()->getLocale();
    $address = $companyInfo->getTranslation('address', $locale, false);
    $socials = $companyInfo->socialLinks();
@endphp

@section('title', __('Contact'))
@section('description', __('seo.contact_desc'))

@section('content')

@include('site.partials.page-header', [
    'title'   => __('site.contact.title'),
    'lead'    => __('site.contact.lead'),
    'eyebrow' => __('Contact'),
    'crumbs'  => [['label' => __('Contact'), 'url' => null]],
])

<section class="bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-10 lg:gap-12">

            <div class="lg:col-span-2 space-y-8">
                {{-- Contact details --}}
                <div>
                    <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 mb-5">{{ __('Contacts') }}</h2>
                    @if($companyInfo->phone || $companyInfo->email || $address)
                        <ul class="space-y-3">
                            @if($companyInfo->phone)
                                <li>
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $companyInfo->phone) }}"
                                       class="group flex items-center gap-4 rounded-xl border border-gray-200 p-4 hover:border-red-200 transition-colors">
                                        <span class="icon-tile">@include('site.partials.icon', ['name' => 'phone', 'class' => 'size-5'])</span>
                                        <span>
                                            <span class="block text-xs text-gray-500">{{ __('Phone') }}</span>
                                            <span class="block font-semibold text-gray-900 group-hover:text-red-600 transition-colors">{{ $companyInfo->phone }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endif
                            @if($companyInfo->email)
                                <li>
                                    <a href="mailto:{{ $companyInfo->email }}"
                                       class="group flex items-center gap-4 rounded-xl border border-gray-200 p-4 hover:border-red-200 transition-colors">
                                        <span class="icon-tile">@include('site.partials.icon', ['name' => 'envelope', 'class' => 'size-5'])</span>
                                        <span class="min-w-0">
                                            <span class="block text-xs text-gray-500">{{ __('Email') }}</span>
                                            <span class="block font-semibold text-gray-900 group-hover:text-red-600 transition-colors break-all">{{ $companyInfo->email }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endif
                            @if($address)
                                <li class="flex items-center gap-4 rounded-xl border border-gray-200 p-4">
                                    <span class="icon-tile">@include('site.partials.icon', ['name' => 'map-pin', 'class' => 'size-5'])</span>
                                    <span>
                                        <span class="block text-xs text-gray-500">{{ __('Address') }}</span>
                                        <address class="not-italic text-gray-800">{{ $address }}</address>
                                    </span>
                                </li>
                            @endif
                        </ul>
                    @else
                        <p class="text-sm text-gray-500">{{ __('site.contact.not_set') }}</p>
                    @endif
                </div>

                {{-- AI assistant shortcut --}}
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6" x-data>
                    <div class="flex items-start gap-4">
                        <span class="icon-tile text-red-600">@include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-5'])</span>
                        <div>
                            <h2 class="font-semibold text-gray-900">{{ __('site.contact.ai_title') }}</h2>
                            <p class="mt-1 text-sm text-gray-600 leading-relaxed">{{ __('site.contact.ai_text') }}</p>
                            <button type="button" @click="$dispatch('belgi-open')" class="btn-secondary mt-4 !py-2.5">
                                {{ __('site.cta.ask_ai') }}
                            </button>
                        </div>
                    </div>
                </div>

                @if($socials)
                    <div>
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 mb-4">{{ __('Social') }}</h2>
                        <ul class="flex flex-wrap gap-2">
                            @foreach($socials as $label => $href)
                                <li>
                                    <a href="{{ $href }}" target="_blank" rel="noopener noreferrer"
                                       class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:border-gray-400 hover:text-gray-900 transition-colors">
                                        {{ $label }}
                                        @include('site.partials.icon', ['name' => 'arrow-up-right', 'class' => 'size-3.5 text-gray-400'])
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Map --}}
            <div class="lg:col-span-3">
                <h2 class="sr-only">{{ __('site.contact.map') }}</h2>
                @if($companyInfo->map_embed)
                    <div class="h-full min-h-80 overflow-hidden rounded-2xl border border-gray-200 [&_iframe]:block [&_iframe]:size-full [&_iframe]:min-h-80 [&_iframe]:border-0">
                        {!! $companyInfo->map_embed !!}
                    </div>
                @else
                    <div class="relative h-full min-h-80 overflow-hidden rounded-2xl border border-gray-200 bg-gray-50 flex items-center justify-center">
                        <div class="absolute inset-0 bg-grid pointer-events-none" aria-hidden="true"></div>
                        <div class="relative text-center px-6">
                            @include('site.partials.icon', ['name' => 'map-pin', 'class' => 'size-8 mx-auto text-gray-400'])
                            <p class="mt-3 text-sm text-gray-500">{{ $address ?: __('Map not configured.') }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>

@endsection
