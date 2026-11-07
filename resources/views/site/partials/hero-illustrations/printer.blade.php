{{-- Label printing: a thermal printer feeding a GS1 barcode label. --}}
@php
    // Deterministic 1D barcode (decorative only): alternating bars and gaps of width 1–3.
    $bars = ''; $x = 0;
    for ($i = 0; $i < 46; $i++) {
        $w = 1 + crc32("bar{$i}") % 3;
        if ($i % 2 === 0) $bars .= "M{$x} 0h{$w}v30h-{$w}z";
        $x += $w;
    }
    $barsWidth = $x;
@endphp

<x-hero-frame path="bdb://print/gs1-128"
    :rows="[['RESOLUTION', '300 dpi'], ['SPEED', '152 mm/s'], ['LABEL', '58 × 40 mm'], ['RIBBON', 'WAX/RESIN'], ['PRINT JOB', 'COMPLETED', true]]"
    :chips="[['qr-code', 'GS1 · DataMatrix'], ['sparkles', 'AI · Belgi', true], ['signal', 'RFID · Inlay']]">
    <div class="relative mx-auto aspect-square w-full max-w-[240px]">
        {{-- Printer body --}}
        <div class="absolute inset-x-0 top-0 h-[42%] rounded-2xl border-2 border-gray-900 bg-white shadow-lg shadow-gray-900/10">
            <div class="absolute left-4 top-4 flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-2 py-1">
                <span class="size-1.5 rounded-full bg-brand-600 animate-blink"></span>
                <span class="font-mono text-[9px] tracking-widest text-gray-600">PRINTING</span>
            </div>
            <div class="absolute right-4 top-4 flex gap-1.5">
                <span class="size-3 rounded-full border border-gray-300"></span>
                <span class="size-3 rounded-full border border-gray-300"></span>
            </div>
            {{-- Output slot --}}
            <div class="absolute inset-x-5 bottom-3 h-1.5 rounded-full bg-gray-900"></div>
        </div>

        {{-- Label feeding out of the slot --}}
        <div class="absolute inset-x-8 top-[calc(42%-0.75rem)] bottom-0 overflow-hidden">
            {{-- Negative delay: the cycle starts with the label already out, not hidden --}}
            <div style="animation-delay: -2.4s" class="animate-feed rounded-b-lg border border-t-0 border-gray-300 bg-white px-3 pb-3 pt-4 shadow-md">
                <div class="flex items-center justify-between font-mono text-[8px] text-gray-500">
                    <span class="font-semibold text-gray-900">BDB</span>
                    <span>LOT 2611-A</span>
                </div>
                <svg viewBox="0 0 {{ $barsWidth }} 30" preserveAspectRatio="none" class="mt-2 h-10 w-full text-gray-900" shape-rendering="crispEdges">
                    <path fill="currentColor" d="{{ $bars }}"/>
                </svg>
                <p class="mt-1 text-center font-mono text-[8px] tracking-wider text-gray-700">(01)04780000123457(21)BDB7Q</p>
                <div class="mt-2 flex items-center justify-between">
                    <span class="h-1 w-16 rounded bg-gray-200"></span>
                    <span class="size-5 border-2 border-gray-900 [background:repeating-conic-gradient(#111827_0_25%,#fff_0_50%)_0_0/5px_5px]"></span>
                </div>
            </div>
        </div>
    </div>
</x-hero-frame>
