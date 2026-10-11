@php
    $locale   = app()->getLocale();
    $_slogan  = $companyInfo->getTranslation('slogan', $locale, false);

    // Section values are already escaped by Blade's @section('x', $value) shorthand.
    $_pageTitle = View::hasSection('title_full')
        ? trim(View::yieldContent('title_full'))
        : (View::hasSection('title') ? trim(View::yieldContent('title')) . ' — BDB Auto ID' : 'BDB Auto ID');
    $_pageDesc  = trim(View::yieldContent('description')) ?: e($_slogan ?: __('seo.home_desc'));
    $_ogImage   = View::hasSection('og_image') ? trim(View::yieldContent('og_image')) : url('/images/logo.jpg');
    $_ogType    = View::hasSection('og_type') ? trim(View::yieldContent('og_type')) : 'website';
    $_ogLocales = ['uz' => 'uz_UZ', 'ru' => 'ru_RU', 'en' => 'en_US'];

    // Path without the locale prefix, for hreflang + language switcher
    $_segments = explode('/', request()->path(), 2);
    $_rest     = isset($_segments[1]) ? '/' . $_segments[1] : '';

    $navLinks = [
        ['url' => route('site.home', $locale) . '#services', 'label' => __('site.nav.services'), 'route' => null],
        ['url' => route('site.about', $locale),      'label' => __('About'),      'route' => 'site.about'],
        ['url' => route('site.brands', $locale),     'label' => __('Brands'),     'route' => 'site.brands'],
        ['url' => route('site.industries', $locale), 'label' => __('Industries'), 'route' => 'site.industries'],
        ['url' => route('site.customers', $locale),  'label' => __('Customers'),  'route' => 'site.customers'],
        ['url' => route('site.news', $locale),       'label' => __('News'),       'route' => 'site.news*'],
        ['url' => route('site.contact', $locale),    'label' => __('Contact'),    'route' => 'site.contact'],
    ];
    $_languages = ['uz' => "O'zbekcha", 'ru' => 'Русский', 'en' => 'English'];
    // Normalized https links; values that aren't a link to that network are left out.
    $_socials = $companyInfo->socialLinks();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{!! $_pageTitle !!}</title>
    <meta name="description" content="{!! $_pageDesc !!}">
    <meta name="theme-color" content="#ffffff">
    <meta name="color-scheme" content="light">
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="icon" type="image/jpeg" href="/images/logo.jpg">

    {{-- hreflang --}}
    @foreach(array_keys($_languages) as $hl)
        <link rel="alternate" hreflang="{{ $hl }}" href="{{ url('/' . $hl . $_rest) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ url('/uz' . $_rest) }}">

    {{-- Open Graph / Twitter --}}
    <meta property="og:site_name" content="BDB Auto ID">
    <meta property="og:title" content="{!! $_pageTitle !!}">
    <meta property="og:description" content="{!! $_pageDesc !!}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="{{ $_ogType }}">
    <meta property="og:image" content="{!! $_ogImage !!}">
    <meta property="og:locale" content="{{ $_ogLocales[$locale] ?? 'uz_UZ' }}">
    @foreach($_ogLocales as $lc => $ogl)
        @if($lc !== $locale)<meta property="og:locale:alternate" content="{{ $ogl }}">@endif
    @endforeach
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{!! $_pageTitle !!}">
    <meta name="twitter:description" content="{!! $_pageDesc !!}">
    <meta name="twitter:image" content="{!! $_ogImage !!}">

    {{-- Structured data --}}
    @php
        $_ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => 'BDB Auto ID',
            'url'      => url('/'),
            'logo'     => url('/images/logo.jpg'),
        ];
        if ($_slogan) $_ld['slogan'] = $_slogan;
        if ($companyInfo->phone) $_ld['telephone'] = $companyInfo->phone;
        if ($companyInfo->email) $_ld['email'] = $companyInfo->email;
        $_addr = $companyInfo->getTranslation('address', $locale, false);
        if ($_addr) $_ld['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $_addr, 'addressCountry' => 'UZ'];
        if ($_socials) $_ld['sameAs'] = array_values($_socials);
    @endphp
    <script type="application/ld+json">{!! json_encode($_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @stack('schema')

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-gray-700 font-sans antialiased">

<a href="#main"
   class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-gray-900 focus:shadow-lg focus:ring-2 focus:ring-red-600">
    {{ __('site.a11y.skip') }}
</a>

{{-- Header --}}
<header class="sticky top-0 z-50 bg-white/90 backdrop-blur supports-[backdrop-filter]:bg-white/75 border-b transition-shadow"
        x-data="{ open: false, scrolled: false }"
        x-init="scrolled = window.scrollY > 8"
        @scroll.window.passive="scrolled = window.scrollY > 8"
        @keydown.escape.window="open = false"
        :class="scrolled ? 'border-gray-200 shadow-sm' : 'border-gray-100'">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center h-16 gap-6">

            <a href="{{ route('site.home', $locale) }}" class="shrink-0 rounded" aria-label="BDB Auto ID — {{ __('Home') }}">
                <img src="/images/logo.jpg" alt="BDB" width="1160" height="634" class="h-[2.7rem] w-auto">
            </a>

            {{-- Desktop nav --}}
            <nav class="hidden lg:flex items-center gap-0.5 flex-1" aria-label="{{ __('site.a11y.main_nav') }}">
                @foreach($navLinks as $link)
                    @php $active = $link['route'] && request()->routeIs($link['route']); @endphp
                    <a href="{{ $link['url'] }}" @if($active) aria-current="page" @endif
                       class="px-3 py-2 rounded-md text-sm font-medium transition-colors
                              {{ $active ? 'text-red-600 bg-red-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            {{-- Locale switcher --}}
            <div class="hidden lg:flex items-center gap-0.5 ml-auto rounded-lg border border-gray-200 p-0.5" role="group" aria-label="{{ __('site.a11y.language') }}">
                @foreach($_languages as $lc => $langName)
                    <a href="{{ url('/' . $lc . $_rest) }}" hreflang="{{ $lc }}" lang="{{ $lc }}" title="{{ $langName }}"
                       @if($locale === $lc) aria-current="true" @endif
                       class="px-2 py-1 text-xs font-semibold rounded-md transition-colors
                              {{ $locale === $lc ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-gray-900' }}">
                        {{ strtoupper($lc) }}
                    </a>
                @endforeach
            </div>

            <a href="{{ route('site.contact', $locale) }}" class="hidden xl:inline-flex btn-primary !px-4 !py-2">
                {{ __('site.cta.quote') }}
            </a>

            {{-- Mobile hamburger --}}
            <button type="button" @click="open = !open"
                    class="lg:hidden ml-auto p-2 -mr-2 rounded-md text-gray-700 hover:bg-gray-100"
                    aria-controls="mobile-menu" :aria-expanded="open.toString()" aria-expanded="false">
                <span class="sr-only">{{ __('site.a11y.menu') }}</span>
                <svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                    <path x-show="!open" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Mobile menu --}}
        <div id="mobile-menu" x-show="open" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             class="lg:hidden border-t border-gray-100 py-3">
            <nav class="space-y-0.5" aria-label="{{ __('site.a11y.main_nav') }}">
                @foreach($navLinks as $link)
                    @php $active = $link['route'] && request()->routeIs($link['route']); @endphp
                    <a href="{{ $link['url'] }}" @click="open = false" @if($active) aria-current="page" @endif
                       class="block px-3 py-2.5 rounded-md text-base font-medium {{ $active ? 'text-red-600 bg-red-50' : 'text-gray-700 hover:bg-gray-50' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between gap-3 px-3">
                <div class="flex gap-1.5" role="group" aria-label="{{ __('site.a11y.language') }}">
                    @foreach($_languages as $lc => $langName)
                        <a href="{{ url('/' . $lc . $_rest) }}" hreflang="{{ $lc }}" lang="{{ $lc }}" title="{{ $langName }}"
                           @if($locale === $lc) aria-current="true" @endif
                           class="px-2.5 py-1.5 text-xs font-semibold rounded-md {{ $locale === $lc ? 'bg-red-600 text-white' : 'text-gray-600 border border-gray-200' }}">
                            {{ strtoupper($lc) }}
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('site.contact', $locale) }}" class="btn-primary !px-4 !py-2">{{ __('site.cta.quote') }}</a>
            </div>
        </div>
    </div>
</header>

<main id="main" tabindex="-1" class="focus:outline-none">
    @yield('content')
</main>

{{-- Footer --}}
<footer class="bg-gray-900 text-gray-400" aria-labelledby="footer-heading">
    <h2 id="footer-heading" class="sr-only">{{ __('site.footer.heading') }}</h2>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-10">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-10">
            <div class="lg:col-span-4">
                <a href="{{ route('site.home', $locale) }}" class="inline-flex rounded-lg bg-white p-2">
                    <img src="/images/logo.jpg" alt="BDB" width="1160" height="634" loading="lazy" class="h-[2.4rem] w-auto">
                </a>
                <p class="mt-4 text-sm leading-relaxed max-w-xs">{{ $_slogan ?: __('site.footer.tagline') }}</p>
                @if($_socials)
                    <ul class="mt-6 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        @foreach($_socials as $label => $href)
                            <li><a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="hover:text-white transition-colors">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="lg:col-span-2">
                <p class="text-white text-sm font-semibold mb-4">{{ __('site.footer.company') }}</p>
                <ul class="space-y-2.5 text-sm">
                    @foreach($navLinks as $link)
                        <li><a href="{{ $link['url'] }}" class="hover:text-white transition-colors">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div class="lg:col-span-3">
                <p class="text-white text-sm font-semibold mb-4">{{ __('News') }}</p>
                <ul class="space-y-2.5 text-sm">
                    @forelse($articleCategories as $cat)
                        @if($cat->getTranslation('slug', $locale, false))
                            <li>
                                <a href="{{ route('site.news.category', [$locale, $cat->getTranslation('slug', $locale, false)]) }}" class="hover:text-white transition-colors">
                                    {{ $cat->getTranslation('name', $locale) }}
                                </a>
                            </li>
                        @endif
                    @empty
                        <li><a href="{{ route('site.news', $locale) }}" class="hover:text-white transition-colors">{{ __('All news') }}</a></li>
                    @endforelse
                </ul>
            </div>

            <div class="lg:col-span-3">
                <p class="text-white text-sm font-semibold mb-4">{{ __('Contact us') }}</p>
                <ul class="space-y-3 text-sm">
                    @if($companyInfo->phone)
                        <li class="flex gap-2.5">
                            @include('site.partials.icon', ['name' => 'phone', 'class' => 'size-4 mt-0.5 shrink-0 text-gray-500'])
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $companyInfo->phone) }}" class="hover:text-white transition-colors">{{ $companyInfo->phone }}</a>
                        </li>
                    @endif
                    @if($companyInfo->email)
                        <li class="flex gap-2.5">
                            @include('site.partials.icon', ['name' => 'envelope', 'class' => 'size-4 mt-0.5 shrink-0 text-gray-500'])
                            <a href="mailto:{{ $companyInfo->email }}" class="hover:text-white transition-colors break-all">{{ $companyInfo->email }}</a>
                        </li>
                    @endif
                    @if($_addr)
                        <li class="flex gap-2.5">
                            @include('site.partials.icon', ['name' => 'map-pin', 'class' => 'size-4 mt-0.5 shrink-0 text-gray-500'])
                            <span>{{ $_addr }}</span>
                        </li>
                    @endif
                    <li class="pt-1" x-data>
                        <button type="button" @click="$dispatch('belgi-open')"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-700 px-3 py-2 text-sm text-gray-200 hover:border-gray-500 hover:text-white transition-colors">
                            @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4'])
                            {{ __('site.cta.ask_ai') }}
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <div class="mt-12 pt-6 border-t border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
            <p>&copy; {{ date('Y') }} BDB Auto ID. {{ __('site.footer.rights') }}</p>
            <p>{{ __('site.footer.made_in') }}</p>
        </div>
    </div>
</footer>

@include('site.partials.chat-widget')

{{-- Analytics --}}
@if($companyInfo->google_analytics)
    {!! $companyInfo->google_analytics !!}
@endif
@if($companyInfo->yandex_metrika)
    {!! $companyInfo->yandex_metrika !!}
@endif

<script src="//unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
@stack('scripts')
</body>
</html>
