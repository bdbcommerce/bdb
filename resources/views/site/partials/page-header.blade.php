{{--
    Inner-page hero. Params: $title (required), $lead (optional), $eyebrow (optional),
    $crumbs (optional array of ['label' => .., 'url' => ..|null]).
--}}
@php $__locale = app()->getLocale(); @endphp
<section class="relative overflow-hidden border-b border-gray-200 bg-white">
    <div class="absolute inset-0 bg-grid mask-fade-r pointer-events-none" aria-hidden="true"></div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-12 md:pt-14 md:pb-16">
        <nav aria-label="{{ __('site.a11y.breadcrumb') }}" class="mb-6">
            <ol class="flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                <li><a href="{{ route('site.home', $__locale) }}" class="hover:text-gray-900 transition-colors rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">{{ __('Home') }}</a></li>
                @foreach(($crumbs ?? [['label' => $title, 'url' => null]]) as $crumb)
                    <li aria-hidden="true">@include('site.partials.icon', ['name' => 'chevron-right', 'class' => 'size-3 text-gray-300'])</li>
                    <li class="min-w-0">
                        @if(! empty($crumb['url']))
                            <a href="{{ $crumb['url'] }}" class="hover:text-gray-900 transition-colors">{{ $crumb['label'] }}</a>
                        @else
                            <span aria-current="page" class="text-gray-700 truncate block max-w-[16rem] sm:max-w-md">{{ $crumb['label'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>
        <p class="eyebrow">{{ $eyebrow ?? 'BDB Auto ID' }}</p>
        <h1 class="mt-3 text-3xl md:text-5xl font-semibold tracking-tight text-gray-900 text-balance max-w-3xl">{{ $title }}</h1>
        @if(! empty($lead))
            <p class="mt-4 text-base md:text-lg text-gray-600 leading-relaxed max-w-2xl text-pretty">{{ $lead }}</p>
        @endif
    </div>
</section>
