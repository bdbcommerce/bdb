@extends('site.layout')

@php
    $locale  = app()->getLocale();
    $slogan  = $companyInfo->getTranslation('slogan', $locale, false);
    $about   = $companyInfo->getTranslation('about', $locale, false);

    $serviceIcons = [
        'barcode'     => 'qr-code',
        'rfid'        => 'signal',
        'mobility'    => 'device-phone-mobile',
        'marking'     => 'check-badge',
        'fiscal'      => 'receipt-percent',
        'integration' => 'wrench-screwdriver',
    ];
    // Hero slides come from admin banners; with none, fall back to the built-in copy.
    $slides = $banners->map(fn ($banner) => [
        'badge'       => $banner->getTranslation('badge', $locale, false),
        'title'       => $banner->getTranslation('title', $locale),
        'lead'        => $banner->getTranslation('subtitle', $locale, false),
        'points'      => $banner->pointList($locale),
        'button_text' => $banner->getTranslation('button_text', $locale, false),
        'button_href' => $banner->buttonHref($locale),
        'show_ai'     => $banner->show_ai_button,
        'image'       => $banner->image ? Storage::url($banner->image) : null,
    ])->all();

    if (! $slides) {
        $slides = [[
            'badge'       => __('site.hero.badge'),
            'title'       => $slogan ?: __('site.hero.title'),
            'lead'        => __('site.hero.lead'),
            'points'      => array_values((array) __('site.hero.points')),
            'button_text' => __('site.cta.quote'),
            'button_href' => route('site.contact', $locale),
            'show_ai'     => true,
            'image'       => null,
        ]];
    }
    $slideCount = count($slides);

    $services = __('site.services.items');
    $services = is_array($services) ? $services : [];

    // Deterministic QR-style matrix for the hero visual (decorative only).
    $qrSize = 25; $qrPath = '';
    $isFinder = fn ($x, $y) => ($x < 8 && $y < 8) || ($x >= $qrSize - 8 && $y < 8) || ($x < 8 && $y >= $qrSize - 8);
    for ($y = 0; $y < $qrSize; $y++) {
        for ($x = 0; $x < $qrSize; $x++) {
            if ($isFinder($x, $y)) continue;
            if ((crc32("bdb{$x}-{$y}") % 100) < 47) $qrPath .= "M{$x} {$y}h1v1h-1z";
        }
    }
    foreach ([[0, 0], [$qrSize - 7, 0], [0, $qrSize - 7]] as [$fx, $fy]) {
        $qrPath .= "M{$fx} {$fy}h7v7h-7zM" . ($fx + 1) . ' ' . ($fy + 1) . 'v5h5v-5z'
                .  'M' . ($fx + 2) . ' ' . ($fy + 2) . 'h3v3h-3z';
    }
@endphp

@section('title_full', 'BDB Auto ID — ' . $slides[0]['title'])
@section('description', $about ? Str::limit(strip_tags($about), 160) : __('seo.home_desc'))

@section('content')

{{-- ============================== HERO (admin: Hero slides) ============================== --}}
<section class="relative overflow-hidden bg-white" aria-labelledby="hero-title"
         @if($slideCount > 1) aria-roledescription="carousel" @endif>
    <div class="absolute inset-0 bg-grid mask-fade pointer-events-none" aria-hidden="true"></div>
    <div class="absolute -top-40 right-[-10%] size-[36rem] rounded-full bg-red-600/[0.06] blur-3xl pointer-events-none" aria-hidden="true"></div>
    <div class="absolute -bottom-40 left-[-10%] size-[30rem] rounded-full bg-gray-400/10 blur-3xl pointer-events-none" aria-hidden="true"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-16 md:pt-20 md:pb-24 lg:pt-24 lg:pb-28"
         x-data="{
             current: 0,
             total: {{ $slideCount }},
             timer: null,
             start() {
                 if (this.total < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                 this.stop(); this.timer = setInterval(() => this.go(this.current + 1), 7000);
             },
             stop() { clearInterval(this.timer); this.timer = null; },
             go(i)  { this.current = (i + this.total) % this.total; }
         }"
         x-init="start()" @mouseenter="stop()" @mouseleave="start()" @focusin="stop()">

        {{-- All slides share one grid cell, so the hero keeps the height of the tallest slide. --}}
        <div class="grid grid-cols-1">
            @foreach($slides as $i => $slide)
                <div class="col-start-1 row-start-1 grid grid-cols-1 items-center gap-12 lg:grid-cols-12 lg:gap-10 transition-[opacity,visibility] duration-700 data-[active=false]:opacity-0 data-[active=false]:invisible"
                     data-active="{{ $i === 0 ? 'true' : 'false' }}"
                     :data-active="(current === {{ $i }}).toString()"
                     @if($slideCount > 1) role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} / {{ $slideCount }}" @endif>

                    {{-- Copy --}}
                    <div class="min-w-0 lg:col-span-7">
                        @if($slide['badge'])
                            <p class="chip !py-1.5 mb-6">
                                <span class="relative flex size-2" aria-hidden="true">
                                    <span class="absolute inline-flex size-full rounded-full bg-red-600 opacity-60 animate-ping"></span>
                                    <span class="relative inline-flex size-2 rounded-full bg-red-600"></span>
                                </span>
                                {{ $slide['badge'] }}
                            </p>
                        @endif

                        @if($i === 0)
                            <h1 id="hero-title" class="text-[1.75rem] min-[360px]:text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tight text-gray-900 leading-[1.08] text-balance break-words hyphens-auto">{{ $slide['title'] }}</h1>
                        @else
                            <h2 class="text-[1.75rem] min-[360px]:text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tight text-gray-900 leading-[1.08] text-balance break-words hyphens-auto">{{ $slide['title'] }}</h2>
                        @endif

                        @if($slide['lead'])
                            <p class="mt-6 text-lg text-gray-600 leading-relaxed max-w-2xl text-pretty">{{ $slide['lead'] }}</p>
                        @endif

                        @if(($slide['button_text'] && $slide['button_href']) || $slide['show_ai'])
                            <div class="mt-9 flex flex-col sm:flex-row gap-3">
                                @if($slide['button_text'] && $slide['button_href'])
                                    <a href="{{ $slide['button_href'] }}" class="btn-primary">
                                        {{ $slide['button_text'] }}
                                        @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
                                    </a>
                                @endif
                                @if($slide['show_ai'])
                                    <button type="button" @click="$dispatch('belgi-open')" class="btn-secondary">
                                        @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4 text-red-600'])
                                        {{ __('site.cta.ask_ai') }}
                                    </button>
                                @endif
                            </div>
                        @endif

                        @if($slide['points'])
                            <ul class="mt-10 grid gap-3 {{ count($slide['points']) >= 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }} text-sm text-gray-600">
                                @foreach($slide['points'] as $point)
                                    <li class="flex items-start gap-2">
                                        @include('site.partials.icon', ['name' => 'check', 'class' => 'size-4 mt-0.5 shrink-0 text-red-600', 'stroke' => '2'])
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    {{-- Visual: the slide image, or the decorative scanner --}}
                    <div class="min-w-0 lg:col-span-5" @if(! $slide['image']) aria-hidden="true" @endif>
                        @if($slide['image'])
                            <div class="relative mx-auto max-w-md">
                                <img src="{{ $slide['image'] }}" alt="{{ $slide['title'] }}"
                                     @if($i > 0) loading="lazy" @endif decoding="async"
                                     class="w-full aspect-[4/3] object-cover rounded-2xl border border-gray-200 shadow-xl shadow-gray-900/5">
                            </div>
                        @else
                            @include('site.partials.hero-scanner')
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if($slideCount > 1)
            <div class="mt-12 flex items-center gap-2">
                <button type="button" @click="go(current - 1)" aria-label="{{ __('site.a11y.prev') }}"
                        class="size-9 rounded-full border border-gray-200 bg-white text-gray-700 hover:border-gray-400 flex items-center justify-center transition-colors">
                    @include('site.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
                </button>
                @foreach($slides as $i => $slide)
                    <button type="button" @click="go({{ $i }})" aria-label="{{ __('site.a11y.slide', ['n' => $i + 1]) }}"
                            aria-current="{{ $i === 0 ? 'true' : 'false' }}"
                            :aria-current="(current === {{ $i }}).toString()"
                            class="h-2 w-2 rounded-full bg-gray-300 hover:bg-gray-400 aria-[current=true]:w-6 aria-[current=true]:bg-red-600 transition-all duration-300"></button>
                @endforeach
                <button type="button" @click="go(current + 1)" aria-label="{{ __('site.a11y.next') }}"
                        class="size-9 rounded-full border border-gray-200 bg-white text-gray-700 hover:border-gray-400 flex items-center justify-center transition-colors">
                    @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
                </button>
            </div>
        @endif
    </div>
</section>

{{-- ============================== KEY NUMBERS ============================== --}}
@if(count($stats))
<section class="border-y border-gray-200 bg-gray-50" aria-label="{{ __('site.stats.heading') }}">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        @php
            $statCols = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-2 md:grid-cols-4', 5 => 'grid-cols-2 md:grid-cols-5'][min(count($stats), 5)];
        @endphp
        <dl class="grid {{ $statCols }} md:divide-x divide-gray-200">
            @foreach($stats as $stat)
                <div class="flex flex-col px-2 sm:px-4 py-8 md:py-10 text-center">
                    <dt class="order-2 mt-1 text-sm text-gray-500">{{ __('site.stats.' . $stat['label']) }}</dt>
                    <dd class="order-1 text-3xl md:text-4xl font-semibold tracking-tight text-gray-900 tabular-nums">{{ number_format($stat['value'], 0, '.', ' ') }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>
@endif

{{-- ============================== SERVICES ============================== --}}
<section id="services" class="bg-white" aria-labelledby="services-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28" x-data>
        <div class="grid gap-6 lg:grid-cols-12 lg:items-end mb-12 md:mb-16">
            <div class="lg:col-span-7">
                <p class="eyebrow">{{ __('site.services.eyebrow') }}</p>
                <h2 id="services-title" class="section-title">{{ __('site.services.title') }}</h2>
            </div>
            <p class="lg:col-span-5 text-gray-600 leading-relaxed">{{ __('site.services.lead') }}</p>
        </div>

        <ol class="grid gap-px overflow-hidden rounded-2xl border border-gray-200 bg-gray-200 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($services as $key => $service)
                <li class="group relative flex flex-col bg-white p-6 md:p-8 transition-colors hover:bg-gray-50">
                    <div class="flex items-start justify-between">
                        <span class="icon-tile size-12 group-hover:border-red-200 group-hover:text-red-600 transition-colors">
                            @include('site.partials.icon', ['name' => $serviceIcons[$key] ?? 'cube', 'class' => 'size-6'])
                        </span>
                        <span class="font-mono text-xs text-gray-300" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
                    </div>
                    <h3 class="mt-6 text-lg font-semibold text-gray-900">{{ $service['title'] }}</h3>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ $service['text'] }}</p>
                    @if(! empty($service['points']))
                        <ul class="mt-5 space-y-2 text-sm text-gray-600">
                            @foreach($service['points'] as $point)
                                <li class="flex items-start gap-2">
                                    <span class="mt-2 size-1 shrink-0 rounded-full bg-red-600" aria-hidden="true"></span>
                                    <span>{{ $point }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if(! empty($service['ask']))
                        <div class="mt-auto pt-6">
                            <button type="button" @click="$dispatch('belgi-open', { message: @js($service['ask']) })"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 hover:text-red-600 transition-colors rounded">
                                @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4'])
                                {{ __('site.services.ask') }}
                            </button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ============================== BRANDS ============================== --}}
<section id="brands" class="bg-gray-50 border-y border-gray-200" aria-labelledby="brands-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between mb-12">
            <div class="max-w-2xl">
                <p class="eyebrow">{{ __('site.brands.eyebrow') }}</p>
                <h2 id="brands-title" class="section-title">{{ __('site.brands.title') }}</h2>
                <p class="section-lead">{{ __('site.brands.lead') }}</p>
            </div>
            @if($brands->isNotEmpty())
                <a href="{{ route('site.brands', $locale) }}" class="link-arrow shrink-0">
                    {{ __('site.brands.all') }}
                    @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
                </a>
            @endif
        </div>

        @if($brands->isEmpty())
            @include('site.partials.empty-state', [
                'icon'  => 'cube',
                'title' => __('site.brands.empty_title'),
                'text'  => __('site.brands.empty_text'),
            ])
        @else
            <ul class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-px overflow-hidden rounded-2xl border border-gray-200 bg-gray-200">
                @foreach($brands->take(12) as $brand)
                    <li class="group flex h-28 items-center justify-center bg-white p-6 transition-colors hover:bg-gray-50"
                        title="{{ $brand->getTranslation('name', $locale) }}">
                        @if($brand->logo)
                            <img src="{{ Storage::url($brand->logo) }}" alt="{{ $brand->getTranslation('name', $locale) }}"
                                 loading="lazy" decoding="async"
                                 class="max-h-10 max-w-full object-contain grayscale opacity-70 transition duration-300 group-hover:grayscale-0 group-hover:opacity-100">
                        @else
                            <span class="text-sm font-semibold tracking-wide text-gray-500 group-hover:text-gray-900 transition-colors text-center">
                                {{ $brand->getTranslation('name', $locale) }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

{{-- ============================== INDUSTRIES & CUSTOMERS ============================== --}}
<section id="industries" class="bg-white" aria-labelledby="industries-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28">
        <div class="max-w-2xl mb-12">
            <p class="eyebrow">{{ __('site.industries.eyebrow') }}</p>
            <h2 id="industries-title" class="section-title">{{ __('site.industries.title') }}</h2>
            <p class="section-lead">{{ __('site.industries.lead') }}</p>
        </div>

        @if($industries->isEmpty())
            @include('site.partials.empty-state', [
                'icon'  => 'building-office',
                'title' => __('site.industries.empty_title'),
                'text'  => __('site.industries.empty_text'),
            ])
        @else
            @php $indCount = $industries->count(); @endphp
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12"
                 x-data="{
                     active: 0,
                     count: {{ $indCount }},
                     go(i) { this.active = (i + this.count) % this.count; this.$nextTick(() => this.$refs['tab' + this.active]?.focus()); }
                 }">
                {{-- Tabs --}}
                <div class="min-w-0 lg:col-span-4">
                    <div role="tablist" aria-label="{{ __('Industries') }}"
                         class="flex lg:flex-col gap-2 overflow-x-auto scrollbar-none -mx-4 px-4 sm:mx-0 sm:px-0 pb-1 lg:pb-0">
                        @foreach($industries as $i => $industry)
                            <button type="button" role="tab" id="ind-tab-{{ $industry->id }}"
                                    aria-controls="ind-panel-{{ $industry->id }}"
                                    x-ref="tab{{ $i }}"
                                    aria-selected="{{ $i === 0 ? 'true' : 'false' }}" tabindex="{{ $i === 0 ? 0 : -1 }}"
                                    :aria-selected="(active === {{ $i }}).toString()"
                                    :tabindex="active === {{ $i }} ? 0 : -1"
                                    @click="active = {{ $i }}"
                                    @keydown.arrow-right.prevent="go({{ $i }} + 1)" @keydown.arrow-down.prevent="go({{ $i }} + 1)"
                                    @keydown.arrow-left.prevent="go({{ $i }} - 1)" @keydown.arrow-up.prevent="go({{ $i }} - 1)"
                                    @keydown.home.prevent="go(0)" @keydown.end.prevent="go(count - 1)"
                                    class="group flex shrink-0 items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-left text-sm font-medium text-gray-600 transition-colors lg:w-full
                                           hover:border-gray-400 hover:text-gray-900
                                           aria-selected:border-red-600 aria-selected:bg-red-50/60 aria-selected:text-gray-900">
                                <span class="text-gray-400 group-aria-selected:text-red-600">
                                    @include('site.partials.icon', ['name' => $industry->icon, 'class' => 'size-5'])
                                </span>
                                <span class="whitespace-nowrap lg:whitespace-normal lg:flex-1">{{ $industry->getTranslation('name', $locale) }}</span>
                                @if($industry->customers->count())
                                    <span class="hidden lg:inline-flex min-w-6 justify-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 tabular-nums">
                                        {{ $industry->customers->count() }}<span class="sr-only"> {{ __('Customers') }}</span>
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Panels --}}
                <div class="min-w-0 lg:col-span-8">
                    @foreach($industries as $i => $industry)
                        <div role="tabpanel" id="ind-panel-{{ $industry->id }}" aria-labelledby="ind-tab-{{ $industry->id }}" tabindex="0"
                             x-show="active === {{ $i }}" @if($i > 0) style="display: none;" @endif
                             class="rounded-2xl border border-gray-200 bg-white p-6 md:p-8 focus-visible:outline-2">
                            <div class="flex items-start gap-4">
                                <span class="icon-tile size-12 text-red-600">
                                    @include('site.partials.icon', ['name' => $industry->icon, 'class' => 'size-6'])
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-xl font-semibold text-gray-900">{{ $industry->getTranslation('name', $locale) }}</h3>
                                    @if($industry->getTranslation('description', $locale, false))
                                        <p class="mt-2 text-gray-600 leading-relaxed">{{ $industry->getTranslation('description', $locale) }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-8 border-t border-gray-100 pt-6">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 mb-4">{{ __('site.industries.customers_here') }}</p>
                                @if($industry->customers->isEmpty())
                                    <p class="text-sm text-gray-500">{{ __('site.industries.no_customers') }}</p>
                                @else
                                    <ul class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                        @foreach($industry->customers->take(8) as $customer)
                                            <li class="group flex h-20 items-center justify-center rounded-xl border border-gray-200 bg-white px-3 hover:border-red-200 transition-colors"
                                                title="{{ $customer->name }}">
                                                @if($customer->logo)
                                                    <img src="{{ Storage::url($customer->logo) }}" alt="{{ $customer->name }}"
                                                         loading="lazy" decoding="async"
                                                         class="max-h-9 max-w-full object-contain grayscale opacity-70 transition duration-300 group-hover:grayscale-0 group-hover:opacity-100">
                                                @else
                                                    <span class="text-xs font-semibold text-gray-600 text-center line-clamp-2">{{ $customer->name }}</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                    <a href="{{ route('site.customers', $locale) }}#industry-{{ $industry->id }}" class="link-arrow mt-6">
                                        {{ __('site.industries.see_customers') }}
                                        @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>

{{-- ============================== AI ASSISTANT ============================== --}}
<section id="assistant" class="relative overflow-hidden bg-gray-50 border-y border-gray-200" aria-labelledby="assistant-title">
    <div class="absolute inset-0 bg-grid mask-fade-l pointer-events-none" aria-hidden="true"></div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28" x-data>
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div>
                <p class="eyebrow">{{ __('site.assistant.eyebrow') }}</p>
                <h2 id="assistant-title" class="section-title">{{ __('site.assistant.title') }}</h2>
                <p class="section-lead">{{ __('site.assistant.lead') }}</p>

                <ul class="mt-8 grid gap-5 sm:grid-cols-2">
                    @foreach(['select' => 'cpu-chip', 'marking' => 'check-badge', 'languages' => 'chat-bubble-left-right', 'callback' => 'phone'] as $feature => $icon)
                        <li class="flex items-start gap-3">
                            <span class="icon-tile">@include('site.partials.icon', ['name' => $icon, 'class' => 'size-5'])</span>
                            <span>
                                <span class="block text-sm font-semibold text-gray-900">{{ __('site.assistant.features.' . $feature . '.title') }}</span>
                                <span class="block text-sm text-gray-600 mt-0.5">{{ __('site.assistant.features.' . $feature . '.text') }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-10 flex flex-col sm:flex-row gap-3">
                    <button type="button" @click="$dispatch('belgi-open')" class="btn-primary">
                        @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4'])
                        {{ __('site.assistant.open') }}
                    </button>
                    <a href="{{ route('site.contact', $locale) }}" class="btn-secondary">{{ __('site.assistant.human') }}</a>
                </div>
            </div>

            {{-- Mock conversation + live starters --}}
            <div class="relative">
                <div class="rounded-2xl border border-gray-200 bg-white shadow-xl shadow-gray-900/5 overflow-hidden">
                    <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-4" aria-hidden="true">
                        <span class="relative flex size-9 items-center justify-center rounded-full bg-gray-900 text-white">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 6v12M7 6v12M9.5 6v12M13 6v12M15 6v12M18 6v12M20 6v12"/></svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Belgi</p>
                            <p class="text-xs text-gray-500">{{ __('site.assistant.status') }}</p>
                        </div>
                    </div>
                    <div class="space-y-3 bg-gray-50/60 px-5 py-6 text-sm" aria-hidden="true">
                        <div class="flex justify-end">
                            <p class="max-w-[85%] rounded-2xl rounded-tr-sm bg-gray-900 px-4 py-2.5 text-white">{{ __('site.assistant.demo_q') }}</p>
                        </div>
                        <div class="flex">
                            <p class="max-w-[85%] rounded-2xl rounded-tl-sm border border-gray-200 bg-white px-4 py-2.5 text-gray-800 leading-relaxed">{{ __('site.assistant.demo_a') }}</p>
                        </div>
                        <div class="flex">
                            <span class="inline-flex items-center gap-1 rounded-2xl rounded-tl-sm border border-gray-200 bg-white px-4 py-3">
                                <span class="size-1.5 rounded-full bg-gray-400 animate-blink"></span>
                                <span class="size-1.5 rounded-full bg-gray-400 animate-blink [animation-delay:.2s]"></span>
                                <span class="size-1.5 rounded-full bg-gray-400 animate-blink [animation-delay:.4s]"></span>
                            </span>
                        </div>
                    </div>
                    <div class="border-t border-gray-100 px-5 py-4">
                        <p class="text-xs font-medium text-gray-500 mb-3">{{ __('site.assistant.try') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach((array) __('chat.starters') as $starter)
                                <button type="button" @click="$dispatch('belgi-open', { message: @js($starter) })"
                                        class="rounded-full border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:border-gray-900 hover:text-gray-900 transition-colors">
                                    {{ $starter }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================== NEWS ============================== --}}
@if($articles->isNotEmpty())
<section class="bg-white" aria-labelledby="news-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 md:py-28">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-12">
            <div>
                <p class="eyebrow">{{ __('News') }}</p>
                <h2 id="news-title" class="section-title">{{ __('Latest news') }}</h2>
            </div>
            <a href="{{ route('site.news', $locale) }}" class="link-arrow shrink-0">
                {{ __('View all news') }}
                @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
            </a>
        </div>
        <div class="grid gap-6 md:grid-cols-3">
            @foreach($articles as $article)
                @include('site.partials.article-card', ['article' => $article])
            @endforeach
        </div>
    </div>
</section>
@endif

@include('site.partials.cta-band')

@endsection
