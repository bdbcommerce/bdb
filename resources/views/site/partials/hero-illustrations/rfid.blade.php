{{-- RFID reading: a UHF reader sending waves to the tags around it. --}}
@php
    $tags = [
        ['left-0 top-1', '0s'],
        ['right-0 top-6', '-0.6s'],
        ['left-3 bottom-4', '-1.2s'],
        ['right-2 bottom-0', '-1.8s'],
    ];
@endphp

<x-hero-frame path="bdb://rfid/uhf-gen2"
    :rows="[['EPC (SGTIN-96)', '3034F4A2…0B7C'], ['RSSI', '−52 dBm'], ['TAGS / SEC', '420'], ['ANTENNA', '4 / 4 OK'], ['INVENTORY', 'IN SYNC', true]]"
    :chips="[['qr-code', 'GS1 · Data Matrix'], ['sparkles', 'AI · Belgi', true], ['cube', 'SSCC · Pallet']]">
    <div class="relative mx-auto aspect-square w-full max-w-[240px]">
        {{-- Radio waves --}}
        @foreach(['0s', '-0.8s', '-1.6s'] as $delay)
            <div class="absolute inset-0 rounded-full border border-red-600/60 animate-wave" style="animation-delay: {{ $delay }}"></div>
        @endforeach
        <div class="absolute inset-[22%] rounded-full border border-dashed border-gray-300"></div>

        {{-- Reader --}}
        <div class="absolute inset-0 m-auto flex size-20 flex-col items-center justify-center gap-1 rounded-2xl border-2 border-gray-900 bg-white shadow-lg shadow-gray-900/10">
            @include('site.partials.icon', ['name' => 'signal', 'class' => 'size-8 text-gray-900'])
            <span class="font-mono text-[9px] tracking-widest text-gray-500">UHF</span>
        </div>

        {{-- Tags: antenna coil + chip; the dot lights up when a tag is read --}}
        @foreach($tags as [$position, $delay])
            <div class="absolute {{ $position }} w-14 rounded-md border border-gray-300 bg-white p-1 shadow-sm">
                <svg viewBox="0 0 56 32" class="w-full text-gray-900" fill="none" stroke="currentColor" stroke-width="1.4">
                    <path d="M4 4h48v24H4zM9 8.5h38v15H9zM14 13h28v6H14z"/>
                    <rect x="24" y="12" width="8" height="8" fill="currentColor" stroke="none"/>
                </svg>
                <span class="absolute -right-1 -top-1 size-2 rounded-full bg-red-600 animate-blink" style="animation-delay: {{ $delay }}"></span>
            </div>
        @endforeach
    </div>
</x-hero-frame>
