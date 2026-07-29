@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('About') . ' — BDB Auto ID')
@section('description', $companyInfo->getTranslation('about', $locale, false)
    ? Str::limit(strip_tags($companyInfo->getTranslation('about', $locale, false)), 160)
    : __('seo.about_desc'))

@section('content')

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    {{-- Page header --}}
    <div class="mb-12 max-w-2xl">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">BDB Auto ID</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900 leading-tight">{{ __('About') }}</h1>
        @if($companyInfo->getTranslation('slogan', $locale, false))
            <p class="mt-4 text-lg text-gray-500">{{ $companyInfo->getTranslation('slogan', $locale, false) }}</p>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-12">

        {{-- About text --}}
        <div class="lg:col-span-2">
            @if($companyInfo->getTranslation('about', $locale, false))
                <div class="text-gray-600 leading-relaxed">
                    {!! nl2br(e($companyInfo->getTranslation('about', $locale, false))) !!}
                </div>
            @endif
        </div>

        {{-- Contacts sidebar --}}
        <div class="space-y-8">
            <div class="bg-gray-50 border border-gray-200 rounded-xl p-6">
                <h2 class="text-sm font-semibold uppercase tracking-widest text-gray-400 mb-5">{{ __('Contacts') }}</h2>
                <ul class="space-y-4 text-sm">
                    @if($companyInfo->phone)
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                            </svg>
                            <a href="tel:{{ $companyInfo->phone }}" class="text-gray-700 hover:text-gray-900 transition-colors">{{ $companyInfo->phone }}</a>
                        </li>
                    @endif
                    @if($companyInfo->email)
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                            </svg>
                            <a href="mailto:{{ $companyInfo->email }}" class="text-gray-700 hover:text-gray-900 transition-colors">{{ $companyInfo->email }}</a>
                        </li>
                    @endif
                    @if($companyInfo->getTranslation('address', $locale, false))
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
                            </svg>
                            <span class="text-gray-600">{{ $companyInfo->getTranslation('address', $locale, false) }}</span>
                        </li>
                    @endif
                </ul>

                {{-- Socials --}}
                @if($companyInfo->telegram || $companyInfo->instagram || $companyInfo->facebook || $companyInfo->linkedin || $companyInfo->youtube)
                    <div class="mt-6 pt-5 border-t border-gray-200 flex flex-wrap gap-3">
                        @foreach([
                            'telegram'  => 'Telegram',
                            'instagram' => 'Instagram',
                            'facebook'  => 'Facebook',
                            'linkedin'  => 'LinkedIn',
                            'youtube'   => 'YouTube',
                        ] as $field => $label)
                            @if($companyInfo->$field)
                                <a href="{{ $companyInfo->$field }}" target="_blank" rel="noopener"
                                   class="text-xs font-medium text-gray-500 hover:text-gray-900 transition-colors">
                                    {{ $label }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <a href="{{ route('site.contact', $locale) }}"
               class="block w-full text-center bg-red-600 text-white text-sm font-semibold px-6 py-3 rounded-lg hover:bg-red-700 transition-colors">
                {{ __('Contact us') }}
            </a>
        </div>

    </div>
</div>

@endsection
