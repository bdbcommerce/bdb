<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'BDB') — BDB Auto ID</title>
    <meta name="description" content="@yield('description', $companyInfo->getTranslation('slogan', app()->getLocale(), false))">
    <link rel="canonical" href="{{ url()->current() }}">

    {{-- hreflang --}}
    @php
        $currentPath = request()->path();
        $segments    = explode('/', $currentPath, 2);
        $rest        = isset($segments[1]) ? '/' . $segments[1] : '';
    @endphp
    @foreach(['uz','ru','en'] as $hl)
        <link rel="alternate" hreflang="{{ $hl }}" href="{{ url('/' . $hl . $rest) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ url('/uz' . $rest) }}">

    {{-- Open Graph --}}
    <meta property="og:title" content="@yield('title', 'BDB Auto ID')">
    <meta property="og:description" content="@yield('description', '')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:type" content="website">
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
    @endif

    {{-- Structured data --}}
    @php
        $_ld = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            'name'     => 'BDB Auto ID',
            'url'      => url('/'),
            'logo'     => url('/images/logo.jpg'),
        ];
        if ($companyInfo->phone) $_ld['telephone'] = $companyInfo->phone;
        if ($companyInfo->email) $_ld['email'] = $companyInfo->email;
        $_addr = $companyInfo->getTranslation('address', app()->getLocale(), false);
        if ($_addr) $_ld['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $_addr];
        $_socials = array_values(array_filter([
            $companyInfo->telegram, $companyInfo->instagram,
            $companyInfo->facebook, $companyInfo->linkedin, $companyInfo->youtube,
        ]));
        if ($_socials) $_ld['sameAs'] = $_socials;
    @endphp
    <script type="application/ld+json">{!! json_encode($_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
    @stack('schema')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-gray-800 font-sans antialiased">

{{-- Navigation --}}
<header class="sticky top-0 z-50 bg-white/95 backdrop-blur border-b border-gray-200" x-data="{ open: false }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center h-16 gap-8">

            {{-- Logo --}}
            <a href="{{ route('site.home', app()->getLocale()) }}" class="shrink-0">
                <img src="/images/logo.jpg" alt="BDB" class="h-9 w-auto">
            </a>

            {{-- Desktop nav --}}
            <nav class="hidden md:flex items-center gap-1 flex-1">
                @php
                    $locale = app()->getLocale();
                    $navLinks = [
                        ['route' => 'site.about',      'label' => __('About')],
                        ['route' => 'site.brands',     'label' => __('Brands')],
                        ['route' => 'site.industries', 'label' => __('Industries')],
                        ['route' => 'site.customers',  'label' => __('Customers')],
                        ['route' => 'site.news',       'label' => __('News')],
                        ['route' => 'site.contact',    'label' => __('Contact')],
                    ];
                @endphp
                @foreach($navLinks as $link)
                    <a href="{{ route($link['route'], $locale) }}"
                       class="px-3 py-2 rounded-md text-sm font-medium transition-colors
                              {{ request()->routeIs($link['route']) ? 'text-red-600 bg-red-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            {{-- Locale switcher --}}
            <div class="hidden md:flex items-center gap-1 ml-auto">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $lc => $label)
                    @php
                        $segments = explode('/', request()->path(), 2);
                        $rest = isset($segments[1]) ? '/' . $segments[1] : '';
                        $href = url('/' . $lc . $rest);
                    @endphp
                    <a href="{{ $href }}"
                       class="px-2 py-1 text-xs font-semibold rounded transition-colors
                              {{ app()->getLocale() === $lc ? 'bg-red-600 text-white' : 'text-gray-500 hover:text-gray-900' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Mobile hamburger --}}
            <button @click="open = !open" class="md:hidden ml-auto p-2 rounded-md text-gray-600 hover:bg-gray-100">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path x-show="!open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    <path x-show="open"  stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Mobile menu --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-1"
             class="md:hidden border-t border-gray-100 py-3 space-y-1">
            @foreach($navLinks as $link)
                <a href="{{ route($link['route'], $locale) }}"
                   class="block px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                    {{ $link['label'] }}
                </a>
            @endforeach
            <div class="flex gap-2 px-3 pt-2 border-t border-gray-100 mt-2">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $lc => $label)
                    @php
                        $segments = explode('/', request()->path(), 2);
                        $rest = isset($segments[1]) ? '/' . $segments[1] : '';
                        $href = url('/' . $lc . $rest);
                    @endphp
                    <a href="{{ $href }}"
                       class="px-2 py-1 text-xs font-semibold rounded {{ app()->getLocale() === $lc ? 'bg-red-600 text-white' : 'text-gray-500 border border-gray-200' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</header>

{{-- Main content --}}
<main>
    @yield('content')
</main>

{{-- Footer --}}
<footer class="bg-gray-900 text-gray-400">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div>
                <img src="/images/logo.jpg" alt="BDB" class="h-8 w-auto mb-3 brightness-0 invert">
                <p class="text-sm leading-relaxed">{{ $companyInfo->getTranslation('slogan', app()->getLocale(), false) }}</p>
            </div>
            <div>
                <p class="text-white text-sm font-semibold mb-3">{{ __('Contact us') }}</p>
                <ul class="space-y-1.5 text-sm">
                    @if($companyInfo->phone)
                        <li><a href="tel:{{ $companyInfo->phone }}" class="hover:text-white transition-colors">{{ $companyInfo->phone }}</a></li>
                    @endif
                    @if($companyInfo->email)
                        <li><a href="mailto:{{ $companyInfo->email }}" class="hover:text-white transition-colors">{{ $companyInfo->email }}</a></li>
                    @endif
                    @if($companyInfo->getTranslation('address', app()->getLocale(), false))
                        <li class="text-gray-500">{{ $companyInfo->getTranslation('address', app()->getLocale(), false) }}</li>
                    @endif
                </ul>
            </div>
            <div>
                <p class="text-white text-sm font-semibold mb-3">{{ __('News') }}</p>
                <ul class="space-y-1.5 text-sm">
                    @foreach($articleCategories as $cat)
                        <li>
                            <a href="{{ route('site.news.category', [app()->getLocale(), $cat->getTranslation('slug', app()->getLocale(), false)]) }}"
                               class="hover:text-white transition-colors">
                                {{ $cat->getTranslation('name', app()->getLocale()) }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="mt-10 pt-6 border-t border-gray-800 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-xs text-gray-600">© {{ date('Y') }} BDB. All rights reserved.</p>
            {{-- Social links --}}
            <div class="flex items-center gap-3">
                @if($companyInfo->telegram)
                    <a href="{{ $companyInfo->telegram }}" target="_blank" rel="noopener" class="text-gray-500 hover:text-white transition-colors text-sm">Telegram</a>
                @endif
                @if($companyInfo->instagram)
                    <a href="{{ $companyInfo->instagram }}" target="_blank" rel="noopener" class="text-gray-500 hover:text-white transition-colors text-sm">Instagram</a>
                @endif
                @if($companyInfo->linkedin)
                    <a href="{{ $companyInfo->linkedin }}" target="_blank" rel="noopener" class="text-gray-500 hover:text-white transition-colors text-sm">LinkedIn</a>
                @endif
            </div>
        </div>
    </div>
</footer>

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
