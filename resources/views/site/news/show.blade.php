@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', $article->getTranslation('title', $locale) . ' — BDB Auto ID')
@section('description', $article->getTranslation('excerpt', $locale, false) ?? '')
@if($article->featured_image)
    @section('og_image', Storage::url($article->featured_image))
@endif

@push('schema')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "NewsArticle",
    "headline": "{{ addslashes($article->getTranslation('title', $locale)) }}",
    "description": "{{ addslashes($article->getTranslation('excerpt', $locale, false) ?? '') }}",
    @if($article->featured_image)
    "image": "{{ url(Storage::url($article->featured_image)) }}",
    @endif
    "datePublished": "{{ $article->published_at?->toIso8601String() }}",
    "dateModified": "{{ $article->updated_at->toIso8601String() }}",
    "publisher": {
        "@type": "Organization",
        "name": "BDB Auto ID",
        "logo": {
            "@type": "ImageObject",
            "url": "{{ url('/images/logo.jpg') }}"
        }
    }
}
</script>
@endpush

@section('content')

<article class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-2 text-xs text-gray-400 mb-8">
        <a href="{{ route('site.news', $locale) }}" class="hover:text-gray-700 transition-colors">{{ __('News') }}</a>
        @if($article->category)
            <span>/</span>
            <a href="{{ route('site.news.category', [$locale, $article->category->getTranslation('slug', $locale, false)]) }}"
               class="hover:text-gray-700 transition-colors">
                {{ $article->category->getTranslation('name', $locale) }}
            </a>
        @endif
        <span>/</span>
        <span class="text-gray-600 truncate max-w-xs">{{ $article->getTranslation('title', $locale) }}</span>
    </nav>

    {{-- Category + date --}}
    <div class="flex items-center gap-3 mb-5">
        @if($article->category)
            <span class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                {{ $article->category->getTranslation('name', $locale) }}
            </span>
            <span class="text-gray-300">·</span>
        @endif
        <span class="text-xs text-gray-400">{{ $article->published_at?->format('d M Y') }}</span>
    </div>

    {{-- Title --}}
    <h1 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight mb-6">
        {{ $article->getTranslation('title', $locale) }}
    </h1>

    {{-- Excerpt --}}
    @if($article->getTranslation('excerpt', $locale, false))
        <p class="text-lg text-gray-500 leading-relaxed mb-8 border-l-2 border-gray-200 pl-4">
            {{ $article->getTranslation('excerpt', $locale) }}
        </p>
    @endif

    {{-- Featured image --}}
    @if($article->featured_image)
        <div class="rounded-xl overflow-hidden mb-10 aspect-video">
            <img src="{{ Storage::url($article->featured_image) }}"
                 alt="{{ $article->getTranslation('title', $locale) }}"
                 loading="lazy" decoding="async"
                 class="w-full h-full object-cover">
        </div>
    @endif

    {{-- Content (Quill HTML) --}}
    @if($article->getTranslation('content', $locale, false))
        <div class="article-content">
            {!! $article->getTranslation('content', $locale) !!}
        </div>
    @endif

    {{-- Share / back --}}
    <div class="mt-12 pt-8 border-t border-gray-100 flex items-center justify-between gap-4">
        <a href="{{ route('site.news', $locale) }}"
           class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">
            ← {{ __('Back to news') }}
        </a>
        @if($article->category)
            <a href="{{ route('site.news.category', [$locale, $article->category->getTranslation('slug', $locale, false)]) }}"
               class="text-xs font-semibold uppercase tracking-widest text-red-500 hover:text-red-700 transition-colors">
                {{ $article->category->getTranslation('name', $locale) }}
            </a>
        @endif
    </div>

</article>

{{-- Related articles --}}
@if($related->isNotEmpty())
<section class="bg-gray-50 border-t border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <h2 class="text-xl font-bold text-gray-900 mb-8">{{ __('Related articles') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($related as $rel)
                @php $catSlug = $rel->category?->getTranslation('slug', $locale, false); @endphp
                <a href="{{ route('site.news.show', [$locale, $catSlug ?? 'news', $rel->getTranslation('slug', $locale, false)]) }}"
                   class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:border-red-200 transition-colors group">
                    @if($rel->featured_image)
                        <div class="aspect-video overflow-hidden">
                            <img src="{{ Storage::url($rel->featured_image) }}"
                                 alt="{{ $rel->getTranslation('title', $locale) }}"
                                 loading="lazy" decoding="async"
                                 class="w-full h-full object-cover grayscale group-hover:grayscale-0 transition-all duration-500">
                        </div>
                    @else
                        <div class="aspect-video bg-gray-50"></div>
                    @endif
                    <div class="p-5">
                        <h3 class="font-semibold text-gray-900 leading-snug line-clamp-2 group-hover:text-red-600 transition-colors">
                            {{ $rel->getTranslation('title', $locale) }}
                        </h3>
                        <p class="text-xs text-gray-400 mt-3">{{ $rel->published_at?->format('d M Y') }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

@endsection
