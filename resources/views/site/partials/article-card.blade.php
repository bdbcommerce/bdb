{{-- Article card. Params: $article, $headingTag (default h3). --}}
@php
    $__locale  = app()->getLocale();
    $__catSlug = $article->category?->getTranslation('slug', $__locale, false);
    $__title   = $article->getTranslation('title', $__locale);
    $__tag     = $headingTag ?? 'h3';
@endphp
<article class="group relative flex flex-col bg-white border border-gray-200 rounded-xl overflow-hidden hover:border-red-200 hover:shadow-sm transition has-[a:focus-visible]:ring-2 has-[a:focus-visible]:ring-red-600 has-[a:focus-visible]:ring-offset-2">
    <div class="aspect-video overflow-hidden bg-gray-100">
        @if($article->featured_image)
            <img src="{{ Storage::url($article->featured_image) }}" alt=""
                 loading="lazy" decoding="async" width="640" height="360"
                 class="w-full h-full object-cover group-hover:scale-[1.02] transition-transform duration-500">
        @else
            <div class="w-full h-full bg-grid flex items-center justify-center text-gray-300" aria-hidden="true">
                @include('site.partials.icon', ['name' => 'newspaper', 'class' => 'size-10'])
            </div>
        @endif
    </div>
    <div class="flex flex-col flex-1 p-5">
        <div class="flex items-center gap-2 text-xs text-gray-500 mb-3">
            @if($article->category)
                <span class="font-semibold uppercase tracking-wide">{{ $article->category->getTranslation('name', $__locale) }}</span>
            @endif
            @if($article->published_at)
                @if($article->category)<span aria-hidden="true" class="text-gray-300">/</span>@endif
                <time datetime="{{ $article->published_at->toDateString() }}">{{ $article->published_at->copy()->locale($__locale)->isoFormat('D MMM YYYY') }}</time>
            @endif
        </div>
        <{{ $__tag }} class="font-semibold text-gray-900 leading-snug line-clamp-2 group-hover:text-red-600 transition-colors">
            <a href="{{ route('site.news.show', [$__locale, $__catSlug ?? 'news', $article->getTranslation('slug', $__locale, false)]) }}"
               class="after:absolute after:inset-0 focus-visible:outline-none">{{ $__title }}</a>
        </{{ $__tag }}>
        @if($article->getTranslation('excerpt', $__locale, false))
            <p class="mt-2 text-sm text-gray-600 line-clamp-3 leading-relaxed">{{ $article->getTranslation('excerpt', $__locale) }}</p>
        @endif
    </div>
</article>
