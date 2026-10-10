{{-- Contact CTA band: quote request + AI assistant. Optional $title, $text. --}}
@php $__locale = app()->getLocale(); @endphp
<section class="bg-gray-50 border-t border-gray-200" aria-labelledby="cta-title">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 md:py-20">
        <div class="relative overflow-hidden rounded-2xl border border-gray-200 bg-white px-6 py-10 sm:px-10 md:px-14 md:py-14">
            <div class="absolute inset-0 bg-grid mask-fade-l pointer-events-none" aria-hidden="true"></div>
            <div class="absolute -right-24 -top-24 size-72 rounded-full bg-red-600/5 blur-3xl pointer-events-none" aria-hidden="true"></div>

            <div class="relative grid gap-10 lg:grid-cols-5 lg:items-center">
                <div class="lg:col-span-3">
                    <p class="eyebrow">{{ __('site.cta.eyebrow') }}</p>
                    <h2 id="cta-title" class="mt-3 text-2xl md:text-4xl font-semibold tracking-tight text-gray-900 text-balance">
                        {{ $title ?? __('site.cta.title') }}
                    </h2>
                    <p class="mt-4 text-gray-600 leading-relaxed max-w-xl">{{ $text ?? __('site.cta.text') }}</p>

                    <div class="mt-8 flex flex-col sm:flex-row gap-3" x-data>
                        <a href="{{ route('site.contact', $__locale) }}" class="btn-primary">
                            {{ __('site.cta.quote') }}
                            @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
                        </a>
                        <button type="button" @click="$dispatch('belgi-open')" class="btn-secondary">
                            @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4'])
                            {{ __('site.cta.ask_ai') }}
                        </button>
                    </div>
                </div>

                @if($companyInfo->phone || $companyInfo->email || $companyInfo->getTranslation('address', $__locale, false))
                    <ul class="lg:col-span-2 space-y-3 text-sm">
                        @if($companyInfo->phone)
                            <li>
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $companyInfo->phone) }}" class="contact-row group">
                                    <span class="icon-tile">@include('site.partials.icon', ['name' => 'phone', 'class' => 'size-5'])</span>
                                    <span>
                                        <span class="block text-xs text-gray-500">{{ __('Phone') }}</span>
                                        <span class="font-medium text-gray-900 group-hover:text-red-600 transition-colors">{{ $companyInfo->phone }}</span>
                                    </span>
                                </a>
                            </li>
                        @endif
                        @if($companyInfo->email)
                            <li>
                                <a href="mailto:{{ $companyInfo->email }}" class="contact-row group">
                                    <span class="icon-tile">@include('site.partials.icon', ['name' => 'envelope', 'class' => 'size-5'])</span>
                                    <span class="min-w-0">
                                        <span class="block text-xs text-gray-500">{{ __('Email') }}</span>
                                        <span class="block font-medium text-gray-900 group-hover:text-red-600 transition-colors truncate">{{ $companyInfo->email }}</span>
                                    </span>
                                </a>
                            </li>
                        @endif
                        @if($companyInfo->getTranslation('address', $__locale, false))
                            <li class="contact-row">
                                <span class="icon-tile">@include('site.partials.icon', ['name' => 'map-pin', 'class' => 'size-5'])</span>
                                <span>
                                    <span class="block text-xs text-gray-500">{{ __('Address') }}</span>
                                    <span class="text-gray-700">{{ $companyInfo->getTranslation('address', $__locale, false) }}</span>
                                </span>
                            </li>
                        @endif
                    </ul>
                @endif
            </div>
        </div>
    </div>
</section>
