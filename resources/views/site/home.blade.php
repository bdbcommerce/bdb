@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', 'BDB Auto ID — ' . ($companyInfo->getTranslation('slogan', $locale, false) ?? 'Uzbekistan'))
@section('description', $companyInfo->getTranslation('about', $locale, false)
    ? Str::limit(strip_tags($companyInfo->getTranslation('about', $locale, false)), 160)
    : __('seo.home_desc'))
@section('og_image', url('/images/logo.jpg'))

@section('content')

{{-- Hero: banner slider or static fallback --}}
@if($banners->isNotEmpty())
<section class="relative overflow-hidden border-b border-gray-100"
         x-data="{
             current: 0,
             total: {{ $banners->count() }},
             timer: null,
             start() { this.timer = setInterval(() => this.next(), 5000); },
             stop()  { clearInterval(this.timer); },
             next()  { this.current = (this.current + 1) % this.total; },
             prev()  { this.current = (this.current - 1 + this.total) % this.total; }
         }"
         x-init="start()"
         @mouseenter="stop()" @mouseleave="start()">

    {{-- Slides --}}
    @foreach($banners as $i => $banner)
    <div x-show="current === {{ $i }}"
         x-transition:enter="transition-opacity duration-700"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="relative min-h-[480px] md:min-h-[560px] flex items-center"
         style="display: none;">

        {{-- Background image --}}
        @if($banner->image)
            <div class="absolute inset-0">
                <img src="{{ Storage::url($banner->image) }}"
                     alt="{{ $banner->getTranslation('title', $locale) }}"
                     class="w-full h-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/40 to-transparent"></div>
            </div>
        @else
            <div class="absolute inset-0 bg-gray-900"></div>
        @endif

        {{-- Content --}}
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 w-full">
            <div class="max-w-2xl">
                <h2 class="text-3xl md:text-5xl font-bold text-white leading-tight tracking-tight mb-4">
                    {{ $banner->getTranslation('title', $locale) }}
                </h2>
                @if($banner->getTranslation('subtitle', $locale, false))
                    <p class="text-white/80 text-lg leading-relaxed mb-8">
                        {{ $banner->getTranslation('subtitle', $locale) }}
                    </p>
                @endif
                @if($banner->getTranslation('button_text', $locale, false) && $banner->button_url)
                    <a href="{{ $banner->button_url }}"
                       class="inline-block bg-red-600 text-white text-sm font-semibold px-7 py-3 rounded-lg hover:bg-red-700 transition-colors">
                        {{ $banner->getTranslation('button_text', $locale) }}
                    </a>
                @endif
            </div>
        </div>
    </div>
    @endforeach

    {{-- Prev / Next arrows --}}
    @if($banners->count() > 1)
    <button @click="prev()" aria-label="Previous"
            class="absolute left-4 top-1/2 -translate-y-1/2 z-10 w-10 h-10 bg-white/20 hover:bg-white/40 rounded-full flex items-center justify-center text-white transition-colors backdrop-blur-sm">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
    </button>
    <button @click="next()" aria-label="Next"
            class="absolute right-4 top-1/2 -translate-y-1/2 z-10 w-10 h-10 bg-white/20 hover:bg-white/40 rounded-full flex items-center justify-center text-white transition-colors backdrop-blur-sm">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </button>

    {{-- Dots --}}
    <div class="absolute bottom-5 left-1/2 -translate-x-1/2 z-10 flex gap-2">
        @foreach($banners as $i => $banner)
            <button @click="current = {{ $i }}" aria-label="Slide {{ $i + 1 }}"
                    :class="current === {{ $i }} ? 'bg-red-600 w-6' : 'bg-white/50 hover:bg-white/80 w-2'"
                    class="h-2 rounded-full transition-all duration-300"></button>
        @endforeach
    </div>
    @endif
</section>

@else

{{-- Static hero (shown when no banners are configured) --}}
<section class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-24 md:py-32">
        <div class="max-w-3xl">
            <p class="text-gray-500 text-sm font-semibold uppercase tracking-widest mb-4">BDB Auto ID</p>
            <h1 class="text-4xl md:text-5xl font-bold leading-tight tracking-tight text-gray-900 mb-6">
                {{ $companyInfo->getTranslation('slogan', $locale, false) ?? 'Auto Identification Solutions' }}
            </h1>
            @if($companyInfo->getTranslation('about', $locale, false))
                <p class="text-gray-500 text-lg leading-relaxed mb-8 max-w-2xl">
                    {{ Str::limit($companyInfo->getTranslation('about', $locale, false), 200) }}
                </p>
            @endif
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('site.contact', $locale) }}"
                   class="bg-red-600 text-white text-sm font-semibold px-6 py-3 rounded-lg hover:bg-red-700 transition-colors">
                    {{ __('Get in touch') }}
                </a>
                <a href="{{ route('site.about', $locale) }}"
                   class="border border-gray-300 text-gray-700 text-sm font-semibold px-6 py-3 rounded-lg hover:border-gray-400 transition-colors">
                    {{ __('Learn more about us') }}
                </a>
            </div>
        </div>
    </div>
</section>

@endif

{{-- Brands --}}
@if($brands->isNotEmpty())
<section class="border-b border-gray-100 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 text-center mb-8">{{ __('Our brands') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-8 md:gap-12">
            @foreach($brands as $brand)
                <div class="group">
                    @if($brand->logo)
                        <img src="{{ Storage::url($brand->logo) }}"
                             alt="{{ $brand->getTranslation('name', $locale) }}"
                             loading="lazy" decoding="async"
                             class="h-8 object-contain grayscale opacity-60 group-hover:opacity-100 group-hover:grayscale-0 transition-all duration-300">
                    @else
                        <span class="text-sm font-semibold text-gray-400 group-hover:text-gray-700 transition-colors">
                            {{ $brand->getTranslation('name', $locale) }}
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- Industries --}}
@if($industries->isNotEmpty())
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="mb-10">
        <h2 class="text-2xl md:text-3xl font-bold text-gray-900">{{ __('Our industries') }}</h2>
        <p class="text-gray-500 mt-2">{{ __('Industries') }}</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($industries as $industry)
            <div class="bg-white border border-gray-200 rounded-xl p-6 hover:border-red-200 transition-colors group">
                @if($industry->icon)
                    <div class="w-10 h-10 mb-4 text-gray-300 group-hover:text-red-500 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                        </svg>
                    </div>
                @endif
                <h3 class="font-semibold text-gray-900 mb-2">{{ $industry->getTranslation('name', $locale) }}</h3>
                @if($industry->getTranslation('description', $locale, false))
                    <p class="text-sm text-gray-500 leading-relaxed line-clamp-3">
                        {{ $industry->getTranslation('description', $locale) }}
                    </p>
                @endif
            </div>
        @endforeach
    </div>
    <div class="mt-8">
        <a href="{{ route('site.industries', $locale) }}"
           class="inline-flex items-center text-sm font-semibold text-red-600 hover:text-red-700 transition-colors">
            {{ __('All industries') }} →
        </a>
    </div>
</section>
@endif

{{-- Latest news --}}
@if($articles->isNotEmpty())
<section class="bg-gray-50 border-t border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
        <div class="flex items-end justify-between mb-10">
            <h2 class="text-2xl md:text-3xl font-bold text-gray-900">{{ __('Latest news') }}</h2>
            <a href="{{ route('site.news', $locale) }}"
               class="text-sm font-semibold text-red-600 hover:text-red-700 transition-colors hidden sm:block">
                {{ __('View all news') }} →
            </a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($articles as $article)
                @php $catSlug = $article->category?->getTranslation('slug', $locale, false); @endphp
                <a href="{{ route('site.news.show', [$locale, $catSlug ?? 'news', $article->getTranslation('slug', $locale, false)]) }}"
                   class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:border-red-200 transition-colors group">
                    @if($article->featured_image)
                        <div class="aspect-video overflow-hidden">
                            <img src="{{ Storage::url($article->featured_image) }}"
                                 alt="{{ $article->getTranslation('title', $locale) }}"
                                 loading="lazy" decoding="async"
                                 class="w-full h-full object-cover grayscale group-hover:grayscale-0 transition-all duration-500">
                        </div>
                    @else
                        <div class="aspect-video bg-gray-100"></div>
                    @endif
                    <div class="p-5">
                        @if($article->category)
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                                {{ $article->category->getTranslation('name', $locale) }}
                            </p>
                        @endif
                        <h3 class="font-semibold text-gray-900 leading-snug mb-2 line-clamp-2 group-hover:text-red-600 transition-colors">
                            {{ $article->getTranslation('title', $locale) }}
                        </h3>
                        @if($article->getTranslation('excerpt', $locale, false))
                            <p class="text-sm text-gray-500 line-clamp-2">
                                {{ $article->getTranslation('excerpt', $locale) }}
                            </p>
                        @endif
                        <p class="text-xs text-gray-400 mt-3">{{ $article->published_at?->format('d M Y') }}</p>
                    </div>
                </a>
            @endforeach
        </div>
        <div class="mt-8 sm:hidden">
            <a href="{{ route('site.news', $locale) }}" class="text-sm font-semibold text-red-600 hover:text-red-700">
                {{ __('View all news') }} →
            </a>
        </div>
    </div>
</section>
@endif

{{-- CTA contact --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="bg-red-600 rounded-2xl px-8 py-12 md:px-16 md:py-16 text-center">
        <h2 class="text-2xl md:text-3xl font-bold text-white mb-4">{{ __('Get in touch') }}</h2>
        @if($companyInfo->phone || $companyInfo->email)
            <p class="text-red-100 mb-8">{{ $companyInfo->phone }}{{ ($companyInfo->phone && $companyInfo->email) ? ' · ' : '' }}{{ $companyInfo->email }}</p>
        @endif
        <a href="{{ route('site.contact', $locale) }}"
           class="inline-block bg-white text-red-600 text-sm font-semibold px-8 py-3 rounded-lg hover:bg-red-50 transition-colors">
            {{ __('Contact us') }}
        </a>
    </div>
</section>

@endsection
