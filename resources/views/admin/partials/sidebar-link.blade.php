{{-- Single sidebar link. Expects $item: route, label, icon (array of path "d" strings), active (bool), badge (int|null), badge_label (string|null). --}}
<li>
    <a href="{{ route($item['route']) }}"
       @if ($item['active']) aria-current="page" @endif
       class="group flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium border-l-2 transition-colors
              focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400
              {{ $item['active']
                 ? 'bg-slate-100 text-slate-900 border-slate-900'
                 : 'border-transparent text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}">
        <svg class="w-[18px] h-[18px] shrink-0 {{ $item['active'] ? 'text-slate-900' : 'text-slate-400 group-hover:text-slate-600' }}"
             fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
            @foreach ($item['icon'] as $d)
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}"/>
            @endforeach
        </svg>
        <span class="flex-1 truncate">{{ $item['label'] }}</span>
        @if (! empty($item['badge']))
            <span class="min-w-5 h-5 px-1.5 rounded-full bg-slate-900 text-white text-[10px] font-semibold inline-flex items-center justify-center">
                {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
                <span class="sr-only">{{ $item['badge_label'] ?? '' }}</span>
            </span>
        @endif
    </a>
</li>
