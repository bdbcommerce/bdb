{{-- Aggregation: marked units packed into boxes, boxes onto an SSCC pallet. --}}
@php
    $unitX = [14, 52, 90, 128, 166, 204];
    $boxes = [[26, 'BOX 01'], [144, 'BOX 02']];
@endphp

<x-hero-frame path="bdb://trace/aggregation"
    :rows="[['SSCC (00)', '147800001234567895'], ['UNITS', '6 → 2 → 1'], ['LEVEL', 'PALLET'], ['STATUS', 'SHIPPED'], ['CHAIN', 'TRACEABLE', true]]"
    :chips="[['qr-code', 'Unit · DataMatrix'], ['sparkles', 'AI · Belgi', true], ['truck', 'Logistics · EDI']]">
    <div class="relative mx-auto aspect-square w-full max-w-[240px]">
        <svg viewBox="0 0 240 240" class="size-full" fill="none">
            {{-- Links (marching dashes show the packing direction) --}}
            <g class="text-red-600 animate-dash" stroke="currentColor" stroke-width="1.2" stroke-dasharray="3 5">
                @foreach($unitX as $i => $x)
                    @php $boxCenter = $i < 3 ? 61 : 179; @endphp
                    <path d="M{{ $x + 11 }} 34 C{{ $x + 11 }} 70 {{ $boxCenter }} 64 {{ $boxCenter }} 100"/>
                @endforeach
                <path d="M61 140 C61 172 120 166 120 196"/>
                <path d="M179 140 C179 172 120 166 120 196"/>
            </g>

            {{-- Units, each with its own mini code --}}
            @foreach($unitX as $i => $x)
                <rect x="{{ $x }}" y="10" width="22" height="22" rx="3" fill="#fff" stroke="#111827" stroke-width="1.5"/>
                <g fill="#111827">
                    @for($c = 0; $c < 9; $c++)
                        @if((crc32("u{$i}-{$c}") % 3) !== 0)
                            <rect x="{{ $x + 4 + ($c % 3) * 5 }}" y="{{ 14 + intdiv($c, 3) * 5 }}" width="4" height="4"/>
                        @endif
                    @endfor
                </g>
            @endforeach

            {{-- Boxes --}}
            @foreach($boxes as [$x, $label])
                <rect x="{{ $x }}" y="100" width="70" height="40" rx="4" fill="#fff" stroke="#111827" stroke-width="1.5"/>
                <path d="M{{ $x + 35 }} 100v12" stroke="#111827" stroke-width="1.5"/>
                <rect x="{{ $x + 8 }}" y="118" width="54" height="14" rx="2" fill="#f9fafb" stroke="#d1d5db"/>
                <text x="{{ $x + 35 }}" y="128" text-anchor="middle" font-family="ui-monospace, monospace" font-size="8" fill="#374151">{{ $label }}</text>
            @endforeach

            {{-- Pallet --}}
            <rect x="40" y="196" width="160" height="22" rx="3" fill="#fff" stroke="#111827" stroke-width="1.5"/>
            <text x="120" y="210" text-anchor="middle" font-family="ui-monospace, monospace" font-size="8" fill="#111827" letter-spacing="1">SSCC · PALLET</text>
            <path d="M44 218v8h20v-8M110 218v8h20v-8M176 218v8h20v-8" stroke="#111827" stroke-width="1.5"/>
        </svg>
    </div>
</x-hero-frame>
