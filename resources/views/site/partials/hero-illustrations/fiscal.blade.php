{{-- Fiscal receipt: an online cash register receipt with a fiscal QR code. --}}
@php
    $lines = [['Item · 4780000123457', '18 000'], ['Item · 4780000123464', '42 500'], ['Item · 4780000123471', '9 900']];

    // Small deterministic QR-style code (decorative only).
    $qrSize = 15; $qrPath = '';
    $isFinder = fn ($x, $y) => ($x < 5 && $y < 5) || ($x >= $qrSize - 5 && $y < 5) || ($x < 5 && $y >= $qrSize - 5);
    for ($y = 0; $y < $qrSize; $y++) {
        for ($x = 0; $x < $qrSize; $x++) {
            if (! $isFinder($x, $y) && (crc32("fq{$x}-{$y}") % 100) < 48) $qrPath .= "M{$x} {$y}h1v1h-1z";
        }
    }
    foreach ([[0, 0], [$qrSize - 4, 0], [0, $qrSize - 4]] as [$fx, $fy]) {
        $qrPath .= "M{$fx} {$fy}h4v4h-4zM".($fx + 1).' '.($fy + 1).'v2h2v-2z';
    }
@endphp

<x-hero-frame path="bdb://fiscal/receipt"
    :rows="[['FISCAL SIGN', '8 0412 7735 19'], ['RECEIPT', '№ 000248'], ['VAT', '12 %'], ['MARKING', 'CHECKED'], ['TAX SERVICE', 'SENT', true]]"
    :chips="[['qr-code', 'Asl Belgi · Code'], ['sparkles', 'AI · Belgi', true], ['receipt-percent', 'Online · Fiscal']]">
    <div class="relative mx-auto aspect-square w-full max-w-[240px]">
        {{-- Receipt with a torn (zigzag) bottom edge --}}
        <div class="absolute inset-y-0 left-1/2 w-[72%] -translate-x-1/2 drop-shadow-md">
            <div class="h-[calc(100%-6px)] bg-white px-3.5 pt-3 border-x border-t border-gray-200 font-mono text-[8px] leading-tight text-gray-700">
                <p class="text-center text-[9px] font-semibold tracking-widest text-gray-900">BDB STORE</p>
                <p class="mt-0.5 text-center text-gray-400">TIN 300 000 000</p>
                <div class="my-2 border-t border-dashed border-gray-300"></div>
                @foreach($lines as [$name, $sum])
                    <p class="flex justify-between gap-2"><span class="truncate">{{ $name }}</span><span>{{ $sum }}</span></p>
                @endforeach
                <div class="my-2 border-t border-dashed border-gray-300"></div>
                <p class="flex justify-between text-[9px] font-semibold text-gray-900"><span>TOTAL</span><span>70 400</span></p>
                <div class="mt-2.5 flex items-center gap-2">
                    <svg viewBox="0 0 {{ $qrSize }} {{ $qrSize }}" class="size-12 shrink-0 text-gray-900" shape-rendering="crispEdges">
                        <path fill="currentColor" fill-rule="evenodd" d="{{ $qrPath }}"/>
                    </svg>
                    <div class="space-y-1 text-gray-500">
                        <p>FISCAL</p>
                        <p class="text-gray-900">OK</p>
                        <p class="inline-flex items-center gap-1 text-brand-600">
                            <span class="size-1.5 rounded-full bg-brand-600 animate-blink"></span> ONLINE
                        </p>
                    </div>
                </div>
            </div>
            <svg viewBox="0 0 120 6" preserveAspectRatio="none" class="block h-1.5 w-full" aria-hidden="true">
                <path d="{{ 'M0 0'.collect(range(0, 19))->map(fn ($i) => ' L'.($i * 6 + 3).' 6 L'.($i * 6 + 6).' 0')->implode('') }}Z" fill="#fff"/>
            </svg>
        </div>
    </div>
</x-hero-frame>
