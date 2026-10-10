@extends('site.layout')

@php
    $locale   = app()->getLocale();
    $title    = $article->getTranslation('title', $locale);
    $excerpt  = $article->getTranslation('excerpt', $locale, false);
    $imageUrl = $article->featured_image ? url(Storage::url($article->featured_image)) : null;
    $catName  = $article->category?->getTranslation('name', $locale);
    $catSlug  = $article->category?->getTranslation('slug', $locale, false);

    $schema = array_filter([
        '@context'      => 'https://schema.org',
        '@type'         => 'NewsArticle',
        'headline'      => Str::limit($title, 110, ''),
        'description'   => $excerpt,
        'image'         => $imageUrl,
        'datePublished' => $article->published_at?->toIso8601String(),
        'dateModified'  => $article->updated_at?->toIso8601String(),
        'inLanguage'    => $locale,
        'mainEntityOfPage' => url()->current(),
        'author'        => ['@type' => 'Organization', 'name' => 'BDB Auto ID'],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => 'BDB Auto ID',
            'logo'  => ['@type' => 'ImageObject', 'url' => url('/images/logo.jpg')],
        ],
    ]);
@endphp

@section('title', $title)
@section('description', $excerpt ? Str::limit($excerpt, 160) : __('seo.news_desc'))
@section('og_type', 'article')
@if($imageUrl)
    @section('og_image', $imageUrl)
@endif

@push('schema')
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@if($article->published_at)
<meta property="article:published_time" content="{{ $article->published_at->toIso8601String() }}">
@endif
@if($catName)
<meta property="article:section" content="{{ $catName }}">
@endif
@endpush

@section('content')

<article>
    <header class="relative overflow-hidden border-b border-gray-200 bg-white">
        <div class="absolute inset-0 bg-grid mask-fade-r pointer-events-none" aria-hidden="true"></div>
        <div class="relative max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-12 md:pt-14">
            <nav aria-label="{{ __('site.a11y.breadcrumb') }}" class="mb-8">
                <ol class="flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                    <li><a href="{{ route('site.home', $locale) }}" class="hover:text-gray-900 transition-colors">{{ __('Home') }}</a></li>
                    <li aria-hidden="true">@include('site.partials.icon', ['name' => 'chevron-right', 'class' => 'size-3 text-gray-300'])</li>
                    <li><a href="{{ route('site.news', $locale) }}" class="hover:text-gray-900 transition-colors">{{ __('News') }}</a></li>
                    @if($catName && $catSlug)
                        <li aria-hidden="true">@include('site.partials.icon', ['name' => 'chevron-right', 'class' => 'size-3 text-gray-300'])</li>
                        <li><a href="{{ route('site.news.category', [$locale, $catSlug]) }}" class="hover:text-gray-900 transition-colors">{{ $catName }}</a></li>
                    @endif
                </ol>
            </nav>

            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500">
                @if($catName)
                    <span class="eyebrow">{{ $catName }}</span>
                @endif
                @if($article->published_at)
                    <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->copy()->locale($locale)->isoFormat('D MMMM YYYY') }}</time>
                @endif
            </div>

            <h1 class="mt-4 text-3xl md:text-5xl font-semibold tracking-tight text-gray-900 leading-tight text-balance">{{ $title }}</h1>

            @if($excerpt)
                <p class="mt-6 text-lg text-gray-600 leading-relaxed text-pretty">{{ $excerpt }}</p>
            @endif
        </div>
    </header>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-16">
        @if($article->featured_image)
            <figure class="mb-10 overflow-hidden rounded-2xl border border-gray-200 bg-gray-100 aspect-video">
                <img src="{{ Storage::url($article->featured_image) }}" alt="{{ $title }}"
                     decoding="async" fetchpriority="high" width="1280" height="720"
                     class="size-full object-cover">
            </figure>
        @endif

        @if($article->getTranslation('content', $locale, false))
            <div class="article-content">
                {!! $article->getTranslation('content', $locale) !!}
            </div>
        @endif

        {{-- Ask the assistant about this topic --}}
        <aside class="mt-12 rounded-2xl border border-gray-200 bg-gray-50 p-6 flex flex-col sm:flex-row sm:items-center gap-4" x-data>
            <span class="icon-tile text-red-600">@include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-5'])</span>
            <div class="flex-1">
                <p class="font-semibold text-gray-900">{{ __('site.news.ask_title') }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ __('site.news.ask_text') }}</p>
            </div>
            <button type="button" @click="$dispatch('belgi-open', { message: @js(__('site.news.ask_prompt', ['title' => Str::limit($title, 120)])) })"
                    class="btn-secondary !py-2.5 shrink-0">{{ __('site.cta.ask_ai') }}</button>
        </aside>

        {{-- Share / back --}}
        <div class="mt-10 pt-8 border-t border-gray-200 flex flex-col-reverse sm:flex-row sm:items-center justify-between gap-4"
             x-data="{ copied: false, copy() { navigator.clipboard?.writeText(location.href).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); }); } }">
            <a href="{{ $catSlug ? route('site.news.category', [$locale, $catSlug]) : route('site.news', $locale) }}"
               class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">
                @include('site.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
                {{ __('Back to news') }}
            </a>
            <div class="flex items-center gap-2 text-sm">
                <span class="text-gray-500">{{ __('site.news.share') }}</span>
                <a href="https://t.me/share/url?url={{ urlencode(url()->current()) }}&text={{ urlencode($title) }}" target="_blank" rel="noopener noreferrer"
                   class="rounded-lg border border-gray-200 px-3 py-1.5 font-medium text-gray-700 hover:border-gray-400 transition-colors">Telegram</a>
                <button type="button" @click="copy()"
                        class="rounded-lg border border-gray-200 px-3 py-1.5 font-medium text-gray-700 hover:border-gray-400 transition-colors">
                    <span x-show="!copied">{{ __('site.news.copy_link') }}</span>
                    <span x-show="copied" x-cloak role="status">{{ __('site.news.copied') }}</span>
                </button>
            </div>
        </div>
    </div>
</article>

@if($related->isNotEmpty())
<section class="bg-gray-50 border-t border-gray-200" aria-labelledby="related-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        <h2 id="related-title" class="text-2xl font-semibold tracking-tight text-gray-900 mb-8">{{ __('Related articles') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($related as $rel)
                @include('site.partials.article-card', ['article' => $rel])
            @endforeach
        </div>
    </div>
</section>
@endif

@endsection
