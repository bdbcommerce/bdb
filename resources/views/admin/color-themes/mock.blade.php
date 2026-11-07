{{--
    Miniature of the public site, coloured only through CSS variables set on a parent
    (--color-brand-*, --color-gray-*, --site-*: see ColorTheme::previewStyle()).
    $compact: smaller version for the gallery cards.
--}}
@php $compact = $compact ?? false; @endphp
<div class="overflow-hidden rounded-lg border border-gray-200 bg-white text-left shadow-sm {{ $compact ? 'text-[10px]' : 'text-xs' }}" aria-hidden="true">
    {{-- Header + nav --}}
    <div class="flex items-center gap-2 border-b border-(color:--site-header-border) bg-(color:--site-header-bg) {{ $compact ? 'px-2.5 py-2' : 'px-4 py-3' }}">
        <span class="rounded bg-white px-1.5 py-0.5 font-bold tracking-tight text-gray-900 ring-1 ring-gray-200">BDB</span>
        <span class="rounded px-1.5 py-0.5 font-medium text-(color:--site-nav-active) bg-(color:--site-nav-active-bg)">Home</span>
        <span class="rounded px-1.5 py-0.5 font-medium text-(color:--site-nav-text)">Brands</span>
        <span class="rounded px-1.5 py-0.5 font-medium text-(color:--site-nav-hover) bg-(color:--site-nav-hover-bg) {{ $compact ? 'hidden sm:inline' : '' }}">News</span>
        <span class="ml-auto rounded-md px-2 py-1 font-semibold bg-(color:--site-btn-bg) text-(color:--site-btn-text)">Shop</span>
    </div>

    {{-- Content --}}
    <div class="bg-white {{ $compact ? 'p-3' : 'p-5' }}">
        <div class="flex items-center gap-1.5 font-semibold uppercase tracking-widest text-gray-500">
            <span class="inline-block h-px w-4 bg-brand-600"></span> Auto ID
        </div>
        <p class="mt-1 font-semibold text-gray-900 {{ $compact ? 'text-xs' : 'text-lg' }}">Barcode &amp; RFID solutions</p>
        @unless($compact)
            <p class="mt-1 text-gray-600">Scanners, printers and labels for product marking.</p>
        @endunless
        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
            <span class="rounded-md px-2.5 py-1 font-semibold shadow-sm bg-(color:--site-btn-bg) text-(color:--site-btn-text)">Contact us</span>
            @unless($compact)
                <span class="rounded-md px-2.5 py-1 font-semibold shadow-sm bg-(color:--site-btn-hover) text-(color:--site-btn-text)">Hover</span>
            @endunless
            <span class="rounded-md border bg-white px-2.5 py-1 font-semibold border-(color:--site-btn2-border) text-(color:--site-btn2-text)">Catalog</span>
            <span class="font-semibold text-(color:--site-link)">Learn more →</span>
        </div>
        @unless($compact)
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                <span class="rounded-full border px-2.5 py-0.5 font-medium border-(color:--site-btn-bg) bg-(color:--site-btn-bg) text-(color:--site-btn-text)">All news</span>
                <span class="rounded-full border border-gray-200 bg-white px-2.5 py-0.5 font-medium text-gray-600">Tax policy</span>
                <span class="font-semibold text-(color:--site-link-hover) underline">Link hover</span>
            </div>
        @endunless
    </div>

    {{-- Footer --}}
    <div class="bg-(color:--site-footer-bg) text-(color:--site-footer-text) {{ $compact ? 'px-3 py-2' : 'px-5 py-4' }}">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <span class="font-semibold text-(color:--site-footer-heading)">Company</span>
            <span>About</span>
            <span class="text-(color:--site-footer-link-hover)">Contact</span>
            @unless($compact)<span>© BDB Auto ID</span>@endunless
        </div>
        @unless($compact)
            <div class="mt-3 border-t border-(color:--site-footer-border) pt-2">Auto ID · RFID · Product marking</div>
        @endunless
    </div>
</div>
