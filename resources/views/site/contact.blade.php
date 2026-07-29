@extends('site.layout')

@php $locale = app()->getLocale(); @endphp
@section('title', __('Contact') . ' — BDB Auto ID')
@section('description', __('seo.contact_desc'))

@section('content')

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

    <div class="mb-12 max-w-2xl">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-3">BDB Auto ID</p>
        <h1 class="text-3xl md:text-4xl font-bold text-gray-900">{{ __('Contact') }}</h1>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">

        {{-- Contact details --}}
        <div class="space-y-8">
            <div>
                <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-5">{{ __('Contacts') }}</h2>
                <ul class="space-y-5">
                    @if($companyInfo->phone)
                        <li class="flex items-start gap-4">
                            <div class="w-9 h-9 bg-gray-100 rounded-lg flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 mb-0.5">{{ __('Phone') }}</p>
                                <a href="tel:{{ $companyInfo->phone }}" class="text-gray-800 font-medium hover:text-gray-900 transition-colors">{{ $companyInfo->phone }}</a>
                            </div>
                        </li>
                    @endif
                    @if($companyInfo->email)
                        <li class="flex items-start gap-4">
                            <div class="w-9 h-9 bg-gray-100 rounded-lg flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 mb-0.5">{{ __('Email') }}</p>
                                <a href="mailto:{{ $companyInfo->email }}" class="text-gray-800 font-medium hover:text-gray-900 transition-colors">{{ $companyInfo->email }}</a>
                            </div>
                        </li>
                    @endif
                    @if($companyInfo->getTranslation('address', $locale, false))
                        <li class="flex items-start gap-4">
                            <div class="w-9 h-9 bg-gray-100 rounded-lg flex items-center justify-center shrink-0">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400 mb-0.5">{{ __('Address') }}</p>
                                <p class="text-gray-700">{{ $companyInfo->getTranslation('address', $locale) }}</p>
                            </div>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Social links --}}
            @if($companyInfo->telegram || $companyInfo->instagram || $companyInfo->facebook || $companyInfo->linkedin || $companyInfo->youtube)
                <div>
                    <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-400 mb-4">{{ __('Social') }}</h2>
                    <div class="flex flex-wrap gap-3">
                        @foreach([
                            'telegram'  => 'Telegram',
                            'instagram' => 'Instagram',
                            'facebook'  => 'Facebook',
                            'linkedin'  => 'LinkedIn',
                            'youtube'   => 'YouTube',
                        ] as $field => $label)
                            @if($companyInfo->$field)
                                <a href="{{ $companyInfo->$field }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-2 border border-gray-200 text-sm font-medium text-gray-600 px-4 py-2 rounded-lg hover:border-gray-400 hover:text-gray-900 transition-colors">
                                    {{ $label }}
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Map --}}
        @if($companyInfo->map_embed)
            <div class="rounded-xl overflow-hidden border border-gray-200 min-h-64">
                {!! $companyInfo->map_embed !!}
            </div>
        @else
            <div class="rounded-xl bg-gray-50 border border-gray-200 min-h-64 flex items-center justify-center">
                <p class="text-gray-400 text-sm">{{ __('Map not configured.') }}</p>
            </div>
        @endif

    </div>

</div>

@endsection
