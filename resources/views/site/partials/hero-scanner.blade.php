{{-- Decorative scanner card for the home hero. Expects $qrSize and $qrPath from site.home. --}}
<div class="relative mx-auto max-w-md">
    <div class="relative rounded-2xl border border-gray-200 bg-white/90 backdrop-blur shadow-xl shadow-gray-900/5 overflow-hidden">
        <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3">
            <span class="size-2.5 rounded-full bg-gray-200"></span>
            <span class="size-2.5 rounded-full bg-gray-200"></span>
            <span class="size-2.5 rounded-full bg-gray-200"></span>
            <span class="ml-3 font-mono text-[11px] text-gray-400">bdb://scan/datamatrix</span>
            <span class="ml-auto inline-flex items-center gap-1 font-mono text-[11px] text-gray-500">
                <span class="size-1.5 rounded-full bg-red-600 animate-blink"></span> LIVE
            </span>
        </div>

        <div class="relative p-8 sm:p-10 bg-dots">
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
        </div>

        <dl class="grid grid-cols-2 gap-px border-t border-gray-100 bg-gray-100 font-mono text-[11px]">
            <div class="bg-white px-4 py-3">
                <dt class="text-gray-400">GTIN (01)</dt>
                <dd class="mt-0.5 text-gray-800">04780000123457</dd>
            </div>
            <div class="bg-white px-4 py-3">
                <dt class="text-gray-400">SERIAL (21)</dt>
                <dd class="mt-0.5 text-gray-800">BDB7Q2XK9</dd>
            </div>
            <div class="bg-white px-4 py-3 col-span-2 flex items-center justify-between">
                <dt class="text-gray-400">ASL BELGI</dt>
                <dd class="inline-flex items-center gap-1 text-gray-900">
                    @include('site.partials.icon', ['name' => 'check-badge', 'class' => 'size-4 text-red-600'])
                    VERIFIED
                </dd>
            </div>
        </dl>
    </div>

    {{-- Floating chips --}}
    <div class="hidden sm:flex absolute -left-8 top-20 chip shadow-md animate-float">
        @include('site.partials.icon', ['name' => 'signal', 'class' => 'size-4 text-gray-900'])
        RFID · UHF Gen2
    </div>
    <div class="hidden sm:flex absolute -right-6 top-1/2 chip shadow-md animate-float [animation-delay:-2s]">
        @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4 text-red-600'])
        AI · Belgi
    </div>
    <div class="hidden sm:flex absolute -left-4 bottom-24 chip shadow-md animate-float [animation-delay:-4s]">
        @include('site.partials.icon', ['name' => 'printer', 'class' => 'size-4 text-gray-900'])
        GS1 · 300 dpi
    </div>
</div>
