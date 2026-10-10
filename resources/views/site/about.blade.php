@extends('site.layout')

@php
    $locale   = app()->getLocale();
    $about    = $companyInfo->getTranslation('about', $locale, false);
    $slogan   = $companyInfo->getTranslation('slogan', $locale, false);
    $services = __('site.services.items');
    $services = is_array($services) ? $services : [];
    $serviceIcons = ['barcode' => 'qr-code', 'rfid' => 'signal', 'mobility' => 'device-phone-mobile', 'marking' => 'check-badge', 'fiscal' => 'receipt-percent', 'integration' => 'wrench-screwdriver'];
@endphp

@section('title', __('About'))
@section('description', $about ? Str::limit(strip_tags($about), 160) : __('seo.about_desc'))

@section('content')

@include('site.partials.page-header', [
    'title' => __('About'),
    'lead'  => $slogan ?: __('site.about.lead'),
])

<section class="bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-12">

            <div class="lg:col-span-2">
                <h2 class="sr-only">{{ __('About') }}</h2>
                @if($about)
                    <div class="text-gray-600 leading-relaxed text-base md:text-lg space-y-4">
                        {!! nl2br(e($about)) !!}
                    </div>
                @else
                    <p class="text-gray-600 leading-relaxed text-base md:text-lg">{{ __('site.about.fallback') }}</p>
                @endif

                {{-- What we do --}}
                @if($services)
                    <div class="mt-14">
                        <p class="eyebrow">{{ __('site.services.eyebrow') }}</p>
                        <h2 class="mt-3 text-2xl font-semibold tracking-tight text-gray-900">{{ __('site.services.title') }}</h2>
                        <ul class="mt-8 grid gap-4 sm:grid-cols-2">
                            @foreach($services as $key => $service)
                                <li class="flex items-start gap-4 rounded-xl border border-gray-200 p-5 hover:border-red-200 transition-colors">
                                    <span class="icon-tile">@include('site.partials.icon', ['name' => $serviceIcons[$key] ?? 'cube', 'class' => 'size-5'])</span>
                                    <span>
                                        <span class="block font-semibold text-gray-900">{{ $service['title'] }}</span>
                                        <span class="block mt-1 text-sm text-gray-600 leading-relaxed">{{ $service['text'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Contacts sidebar --}}
            <aside class="space-y-6 lg:sticky lg:top-24 self-start" aria-labelledby="about-contacts">
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <h2 id="about-contacts" class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 mb-5">{{ __('Contacts') }}</h2>
                    @if($companyInfo->phone || $companyInfo->email || $companyInfo->getTranslation('address', $locale, false))
                        <ul class="space-y-4 text-sm">
                            @if($companyInfo->phone)
                                <li class="flex items-start gap-3">
                                    @include('site.partials.icon', ['name' => 'phone', 'class' => 'size-5 shrink-0 text-gray-400'])
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $companyInfo->phone) }}" class="text-gray-900 font-medium hover:text-red-600 transition-colors">{{ $companyInfo->phone }}</a>
                                </li>
                            @endif
                            @if($companyInfo->email)
                                <li class="flex items-start gap-3">
                                    @include('site.partials.icon', ['name' => 'envelope', 'class' => 'size-5 shrink-0 text-gray-400'])
                                    <a href="mailto:{{ $companyInfo->email }}" class="text-gray-900 font-medium hover:text-red-600 transition-colors break-all">{{ $companyInfo->email }}</a>
                                </li>
                            @endif
                            @if($companyInfo->getTranslation('address', $locale, false))
                                <li class="flex items-start gap-3">
                                    @include('site.partials.icon', ['name' => 'map-pin', 'class' => 'size-5 shrink-0 text-gray-400'])
                                    <span class="text-gray-700">{{ $companyInfo->getTranslation('address', $locale, false) }}</span>
                                </li>
                            @endif
                        </ul>
                    @else
                        <p class="text-sm text-gray-500">{{ __('site.contact.not_set') }}</p>
                    @endif

                    @php
                        $socials = $companyInfo->socialLinks();
                    @endphp
                    @if($socials)
                        <ul class="mt-6 pt-5 border-t border-gray-200 flex flex-wrap gap-x-4 gap-y-2">
                            @foreach($socials as $label => $href)
                                <li><a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="text-xs font-medium text-gray-600 hover:text-gray-900 transition-colors">{{ $label }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <a href="{{ route('site.contact', $locale) }}" class="btn-primary w-full">{{ __('Contact us') }}</a>
            </aside>
        </div>
    </div>
</section>

@include('site.partials.cta-band')

@endsection
