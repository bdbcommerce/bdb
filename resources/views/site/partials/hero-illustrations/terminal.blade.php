{{-- Mobile computer: a handheld terminal doing a stock count. --}}
@php
    $items = [['4780000123457', '24'], ['4780000123464', '12'], ['4780000123471', '36']];
@endphp

<x-hero-frame path="bdb://mobile/stock-count"
    :rows="[['LOCATION', 'A-03-02'], ['COUNTED', '248 / 250'], ['DEVICE', 'Android · 2D'], ['SYNC', 'Wi-Fi · 4G'], ['STOCK', 'MATCHED', true]]"
    :chips="[['qr-code', '2D · Imager'], ['sparkles', 'AI · Belgi', true], ['archive-box', 'WMS · Sync']]">
    <div class="relative mx-auto aspect-square w-full max-w-[240px]">
        {{-- Scan beam leaving the top of the device --}}
        <div class="absolute left-1/2 top-0 h-[14%] w-24 -translate-x-1/2 bg-gradient-to-t from-brand-600/40 to-transparent [clip-path:polygon(42%_100%,58%_100%,100%_0,0_0)] animate-blink"></div>
        <div class="absolute left-1/2 top-0 h-0.5 w-24 -translate-x-1/2 bg-brand-600 shadow-[0_0_10px_2px_color-mix(in_srgb,var(--color-brand-600)_50%,transparent)] animate-blink"></div>

        {{-- Device --}}
        <div class="absolute inset-x-[18%] top-[14%] bottom-0 overflow-hidden rounded-[1.6rem] border-2 border-gray-900 bg-white p-2.5 shadow-lg shadow-gray-900/10">
            <div class="mx-auto mb-2 h-1 w-8 rounded-full bg-gray-900"></div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-2 font-mono text-[8px] leading-tight">
                <div class="flex items-center justify-between text-gray-900">
                    <span class="font-semibold tracking-wider">STOCK COUNT</span>
                    <span class="text-gray-500">A-03</span>
                </div>
                <ul class="mt-1.5 space-y-1">
                    @foreach($items as $i => [$code, $qty])
                        <li class="flex items-center justify-between rounded border border-gray-200 bg-white px-1.5 py-1">
                            <span class="truncate text-gray-700">{{ $code }}</span>
                            <span class="ml-1 inline-flex items-center gap-0.5 text-gray-900">
                                {{ $qty }}
                                @include('site.partials.icon', ['name' => 'check', 'class' => 'size-2.5 text-brand-600', 'stroke' => '2.5'])
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-2 h-1 overflow-hidden rounded-full bg-gray-200">
                    <div class="h-full origin-left rounded-full bg-brand-600 animate-fill"></div>
                </div>
            </div>
            {{-- Keypad (dropped on very narrow phones so the device content fits) --}}
            <div class="mt-2 grid grid-cols-3 gap-1 max-[359px]:hidden">
                @for($k = 0; $k < 6; $k++)
                    <span class="h-2.5 rounded-sm border border-gray-300"></span>
                @endfor
            </div>
            <div class="mx-auto mt-2 h-3 w-12 rounded-full border-2 border-gray-900"></div>
        </div>
    </div>
</x-hero-frame>
