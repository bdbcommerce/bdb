@extends('site.layout')

@php
    $locale = app()->getLocale();
    $categoryName = isset($category) ? $category->getTranslation('name', $locale) : null;
@endphp

@section('title', ($categoryName ? $categoryName . ' — ' : '') . __('News'))
@section('description', $categoryName ? Str::limit($categoryName . ' — ' . __('seo.news_desc'), 160) : __('seo.news_desc'))

@if($articles->previousPageUrl())
    @push('schema')<link rel="prev" href="{{ $articles->previousPageUrl() }}">@endpush
@endif
@if($articles->nextPageUrl())
    @push('schema')<link rel="next" href="{{ $articles->nextPageUrl() }}">@endpush
@endif

@section('content')

@include('site.partials.page-header', [
    'title'   => $categoryName ?? __('site.news.title'),
    'lead'    => __('site.news.lead'),
    'eyebrow' => __('News'),
    'crumbs'  => $categoryName
        ? [['label' => __('News'), 'url' => route('site.news', $locale)], ['label' => $categoryName, 'url' => null]]
        : [['label' => __('News'), 'url' => null]],
])

<section class="bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-16">

        {{-- Category tabs --}}
        @if($categories->isNotEmpty())
            <nav aria-label="{{ __('site.news.categories') }}" class="mb-10">
                <ul class="flex gap-2 overflow-x-auto scrollbar-none -mx-4 px-4 sm:mx-0 sm:px-0 sm:flex-wrap pb-1">
                    <li>
                        <a href="{{ route('site.news', $locale) }}" @if(! isset($category)) aria-current="page" @endif
                           class="tab inline-flex {{ ! isset($category) ? 'tab-active' : 'tab-inactive' }}">{{ __('All') }}</a>
                    </li>
                    @foreach($categories as $cat)
                        @continue(! $cat->getTranslation('slug', $locale, false))
                        @php $isActive = isset($category) && $category->id === $cat->id; @endphp
                        <li>
                            <a href="{{ route('site.news.category', [$locale, $cat->getTranslation('slug', $locale, false)]) }}" @if($isActive) aria-current="page" @endif
                               class="tab inline-flex {{ $isActive ? 'tab-active' : 'tab-inactive' }}">{{ $cat->getTranslation('name', $locale) }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif

        @if($articles->isEmpty())
            @include('site.partials.empty-state', [
                'icon'      => 'newspaper',
                'title'     => __('No articles yet.'),
                'text'      => __('site.news.empty_text'),
            ])
        @else
            <h2 class="sr-only">{{ $categoryName ?? __('News') }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($articles as $article)
                    @include('site.partials.article-card', ['article' => $article])
                @endforeach
            </div>

            @if($articles->hasPages())
                <div class="mt-14">{{ $articles->links() }}</div>
            @endif
        @endif
    </div>
</section>

@include('site.partials.cta-band')

@endsection
