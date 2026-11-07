{{--
    Shared "app window" card for the hero illustrations.
    Props: path (mono caption in the title bar), rows ([label, value] pairs; a third
    item `true` makes the row full width and highlights the value), chips
    ([icon, text, accent?] floating badges, hidden on small screens).
--}}
@props(['path', 'rows' => [], 'chips' => []])

<div class="relative mx-auto max-w-md">
    <div class="relative rounded-2xl border border-gray-200 bg-white/90 backdrop-blur shadow-xl shadow-gray-900/5 overflow-hidden">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3">
            <span class="size-2.5 rounded-full bg-gray-200"></span>
            <span class="size-2.5 rounded-full bg-gray-200"></span>
            <span class="size-2.5 rounded-full bg-gray-200"></span>
            <span class="ml-3 font-mono text-[11px] text-gray-400 truncate">{{ $path }}</span>
            <span class="ml-auto inline-flex shrink-0 items-center gap-1 font-mono text-[11px] text-gray-500">
                <span class="size-1.5 rounded-full bg-brand-600 animate-blink"></span> LIVE
            </span>
        </div>

        <div class="relative p-8 sm:p-10 bg-dots">
            {{ $slot }}
        </div>

        @if($rows)
            <dl class="grid grid-cols-2 gap-px border-t border-gray-100 bg-gray-100 font-mono text-[11px]">
                @foreach($rows as $row)
                    @if($row[2] ?? false)
                        <div class="bg-white px-4 py-3 col-span-2 flex items-center justify-between">
                            <dt class="text-gray-400">{{ $row[0] }}</dt>
                            <dd class="inline-flex items-center gap-1 text-gray-900">
                                @include('site.partials.icon', ['name' => 'check-badge', 'class' => 'size-4 text-brand-600'])
                                {{ $row[1] }}
                            </dd>
                        </div>
                    @else
                        <div class="bg-white px-4 py-3">
                            <dt class="text-gray-400">{{ $row[0] }}</dt>
                            <dd class="mt-0.5 text-gray-800">{{ $row[1] }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        @endif
    </div>

    @php
        $chipPositions = ['-left-8 top-20', '-right-6 top-1/2 [animation-delay:-2s]', '-left-4 bottom-24 [animation-delay:-4s]'];
    @endphp
    @foreach(array_slice($chips, 0, 3) as $i => $chip)
        <div class="hidden sm:flex absolute {{ $chipPositions[$i] }} chip shadow-md animate-float">
            @include('site.partials.icon', ['name' => $chip[0], 'class' => 'size-4 '.(($chip[2] ?? false) ? 'text-brand-600' : 'text-gray-900')])
            {{ $chip[1] }}
        </div>
    @endforeach
</div>
