{{-- 2D code scanning: a Data Matrix-style code under a scanning laser. --}}
@php
    // Deterministic QR-style matrix (decorative only).
    $qrSize = 25; $qrPath = '';
    $isFinder = fn ($x, $y) => ($x < 8 && $y < 8) || ($x >= $qrSize - 8 && $y < 8) || ($x < 8 && $y >= $qrSize - 8);
    for ($y = 0; $y < $qrSize; $y++) {
        for ($x = 0; $x < $qrSize; $x++) {
            if ($isFinder($x, $y)) continue;
            if ((crc32("bdb{$x}-{$y}") % 100) < 47) $qrPath .= "M{$x} {$y}h1v1h-1z";
        }
    }
    foreach ([[0, 0], [$qrSize - 7, 0], [0, $qrSize - 7]] as [$fx, $fy]) {
        $qrPath .= "M{$fx} {$fy}h7v7h-7zM".($fx + 1).' '.($fy + 1).'v5h5v-5z'
                .  'M'.($fx + 2).' '.($fy + 2).'h3v3h-3z';
    }
@endphp

<x-hero-frame path="bdb://scan/datamatrix"
    :rows="[['GTIN (01)', '04780000123457'], ['SERIAL (21)', 'BDB7Q2XK9'], ['ASL BELGI', 'VERIFIED', true]]"
    :chips="[['signal', 'RFID · UHF Gen2'], ['sparkles', 'AI · Belgi', true], ['printer', 'GS1 · 300 dpi']]">
    <div class="relative mx-auto aspect-square w-full max-w-[240px]">
        {{-- Corner brackets (echo the logo frames) --}}
        <svg class="absolute -inset-4 size-[calc(100%+2rem)] text-red-600" viewBox="0 0 100 100" fill="none" stroke="currentColor" stroke-width="1.2" preserveAspectRatio="none">
            <path d="M2 16V2h14M84 2h14v14M98 84v14H84M16 98H2V84" vector-effect="non-scaling-stroke"/>
        </svg>
        <svg viewBox="0 0 {{ $qrSize }} {{ $qrSize }}" class="size-full text-gray-900" shape-rendering="crispEdges">
            <path fill="currentColor" fill-rule="evenodd" d="{{ $qrPath }}"/>
        </svg>
        {{-- Laser --}}
        <div class="absolute -inset-x-3 inset-y-0">
            <div class="animate-scan absolute inset-x-0 top-0 h-0.5 bg-red-600 shadow-[0_0_12px_2px_rgba(220,38,38,0.55)]"></div>
        </div>
    </div>
</x-hero-frame>
