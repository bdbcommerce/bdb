@if ($paginator->hasPages())
<nav role="navigation" aria-label="Pagination" class="flex items-center justify-center gap-1 flex-wrap">

    {{-- Previous --}}
    @if ($paginator->onFirstPage())
        <span class="px-3 py-2 text-sm text-gray-300 border border-gray-200 rounded-lg cursor-not-allowed select-none">←</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}"
           class="px-3 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:border-gray-400 hover:text-gray-900 transition-colors">←</a>
    @endif

    {{-- Pages --}}
    @foreach ($elements as $element)
        @if (is_string($element))
            <span class="px-3 py-2 text-sm text-gray-400">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="px-3 py-2 text-sm font-semibold bg-red-600 text-white border border-red-600 rounded-lg">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="px-3 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:border-gray-400 hover:text-gray-900 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Next --}}
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}"
           class="px-3 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:border-gray-400 hover:text-gray-900 transition-colors">→</a>
    @else
        <span class="px-3 py-2 text-sm text-gray-300 border border-gray-200 rounded-lg cursor-not-allowed select-none">→</span>
    @endif

</nav>
@endif
