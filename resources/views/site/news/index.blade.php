@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', (isset($category) ? $category->getTranslation('name', $locale) . ' — ' : '') . __('News') . ' — BDB Auto ID')
@section('description', isset($category)
    ? Str::limit($category->getTranslation('name', $locale) . ' — ' . __('seo.news_desc'), 160)
    : __('seo.news_desc'))

@section('content')

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    {{-- Page header --}}
    <div class="mb-10">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">BDB Auto ID</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">
            {{ isset($category) ? $category->getTranslation('name', $locale) : __('News') }}
        </h1>
    </div>

    {{-- Category tabs --}}
    @if($categories->isNotEmpty())
        <div class="flex flex-wrap gap-2 mb-10">
            <a href="{{ route('site.news', $locale) }}"
               class="px-4 py-1.5 text-sm font-medium rounded-full transition-colors
                      {{ ! isset($category) ? 'bg-red-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-400' }}">
                {{ __('All') }}
            </a>
            @foreach($categories as $cat)
                <a href="{{ route('site.news.category', [$locale, $cat->getTranslation('slug', $locale, false)]) }}"
                   class="px-4 py-1.5 text-sm font-medium rounded-full transition-colors
                          {{ (isset($category) && $category->id === $cat->id) ? 'bg-red-600 text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-400' }}">
                    {{ $cat->getTranslation('name', $locale) }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- Articles grid --}}
    @if($articles->isEmpty())
        <div class="py-20 text-center text-gray-400">
            <p class="text-lg">{{ __('No articles yet.') }}</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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
                        <div class="aspect-video bg-gray-50"></div>
                    @endif
                    <div class="p-5">
                        @if($article->category)
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                                {{ $article->category->getTranslation('name', $locale) }}
                            </p>
                        @endif
                        <h2 class="font-semibold text-gray-900 leading-snug mb-2 line-clamp-2 group-hover:text-red-600 transition-colors">
                            {{ $article->getTranslation('title', $locale) }}
                        </h2>
                        @if($article->getTranslation('excerpt', $locale, false))
                            <p class="text-sm text-gray-500 line-clamp-3 leading-relaxed">
                                {{ $article->getTranslation('excerpt', $locale) }}
                            </p>
                        @endif
                        <p class="text-xs text-gray-400 mt-4">{{ $article->published_at?->format('d M Y') }}</p>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($articles->hasPages())
            <div class="mt-12 flex justify-center">
                {{ $articles->links() }}
            </div>
        @endif
    @endif

</div>

@endsection
