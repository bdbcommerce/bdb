@extends('admin.layout')
@section('title', 'Company Info')
@section('heading', 'Company Information')

@section('content')
<div class="w-full">
    <form method="POST" action="{{ route('admin.company-info.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Address --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div class="flex items-center gap-2 mb-1">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>
                </svg>
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Address</h2>
            </div>
            <div x-data="{ locale: 'uz' }">
                <div class="flex gap-1 mb-3 border-b border-slate-200">
                    @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                        <button type="button" @click="locale = '{{ $code }}'"
                                :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                                class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                    @endforeach
                </div>
                @foreach(['uz', 'ru', 'en'] as $locale)
                    <div x-show="locale === '{{ $locale }}'">
                        <textarea name="address_{{ $locale }}" rows="2"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                                  placeholder="Address ({{ strtoupper($locale) }})">{{ old("address_$locale", $info->getTranslation('address', $locale, false)) }}</textarea>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Slogan --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div class="flex items-center gap-2 mb-1">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/>
                </svg>
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Slogan</h2>
            </div>
            <div x-data="{ locale: 'uz' }">
                <div class="flex gap-1 mb-3 border-b border-slate-200">
                    @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                        <button type="button" @click="locale = '{{ $code }}'"
                                :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                                class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                    @endforeach
                </div>
                @foreach(['uz', 'ru', 'en'] as $locale)
                    <div x-show="locale === '{{ $locale }}'">
                        <input type="text" name="slogan_{{ $locale }}"
                               value="{{ old("slogan_$locale", $info->getTranslation('slogan', $locale, false)) }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                               placeholder="Slogan ({{ strtoupper($locale) }})">
                    </div>
                @endforeach
            </div>
        </div>

        {{-- About --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div class="flex items-center gap-2 mb-1">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                </svg>
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">About</h2>
            </div>
            <div x-data="{ locale: 'uz' }">
                <div class="flex gap-1 mb-3 border-b border-slate-200">
                    @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                        <button type="button" @click="locale = '{{ $code }}'"
                                :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                                class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                    @endforeach
                </div>
                @foreach(['uz', 'ru', 'en'] as $locale)
                    <div x-show="locale === '{{ $locale }}'">
                        <textarea name="about_{{ $locale }}" rows="5"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                                  placeholder="About the company ({{ strtoupper($locale) }})">{{ old("about_$locale", $info->getTranslation('about', $locale, false)) }}</textarea>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Contact --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div class="flex items-center gap-2 mb-1">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/>
                </svg>
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Contact</h2>
            </div>
            @foreach(['phone' => 'Phone', 'email' => 'Email'] as $field => $label)
                <div>
                    <label for="{{ $field }}" class="block text-sm font-medium text-slate-700 mb-1">{{ $label }}</label>
                    <input type="text" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $info->$field) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>
            @endforeach
            <p class="text-xs text-slate-400 pt-2">Social networks: paste the full link or just the username (e.g. @bdb_uz). It is saved as a full https link.</p>
            @foreach(\App\Models\CompanyInfo::SOCIALS as $field => $social)
                @php $current = \App\Models\CompanyInfo::socialUrl($field, $info->$field); @endphp
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label for="{{ $field }}" class="text-sm font-medium text-slate-700">{{ $social['label'] }}</label>
                        @if($current)
                            <a href="{{ $current }}" target="_blank" rel="noopener noreferrer" class="text-xs text-slate-500 underline hover:text-slate-900">Open<span class="sr-only"> {{ $social['label'] }} link in a new tab</span></a>
                        @elseif($info->$field)
                            <span class="text-xs text-red-700">Saved value is not a valid {{ $social['label'] }} link — it is hidden on the site</span>
                        @endif
                    </div>
                    <input type="text" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $info->$field) }}"
                           placeholder="{{ $social['example'] }}" autocomplete="off" spellcheck="false"
                           @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                           class="w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 {{ $errors->has($field) ? 'border-red-400' : 'border-slate-300' }}">
                    @error($field)
                        <p id="{{ $field }}-error" class="text-xs text-red-700 mt-1">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>

        {{-- Google Maps --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div class="flex items-center gap-2 mb-1">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z"/>
                </svg>
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Google Maps Embed</h2>
            </div>
            <textarea name="map_embed" rows="3"
                      class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-slate-400"
                      placeholder='<iframe src="https://maps.google.com/..." ...></iframe>'>{{ old('map_embed', $info->map_embed) }}</textarea>
            <p class="text-xs text-slate-400">Paste the full iframe embed code from Google Maps "Share → Embed a map".</p>
        </div>

        {{-- Analytics --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div class="flex items-center gap-2 mb-1">
                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                </svg>
                <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Analytics</h2>
            </div>
            <p class="text-xs text-slate-400">Paste the full script tag from each platform. It will be injected into every public page.</p>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Google Analytics (gtag / GA4)</label>
                <textarea name="google_analytics" rows="4"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-slate-400"
                          placeholder="<!-- Google tag (gtag.js) -->&#10;<script async src=&quot;https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX&quot;></script>&#10;...">{{ old('google_analytics', $info->google_analytics) }}</textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Yandex Metrica</label>
                <textarea name="yandex_metrika" rows="4"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-slate-400"
                          placeholder="<!-- Yandex.Metrika counter -->&#10;<script type=&quot;text/javascript&quot; >&#10;...">{{ old('yandex_metrika', $info->yandex_metrika) }}</textarea>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">
                Save Changes
            </button>
        </div>
    </form>

    {{-- Sitemap --}}
    <div class="mt-6 bg-white border border-slate-200 rounded-xl p-6">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-800">XML Sitemap</p>
                <p class="text-xs text-slate-500 mt-0.5">Generates <code class="bg-slate-100 px-1 rounded">public/sitemap.xml</code> with all localised URLs for published articles and static pages. Submit this URL to Google Search Console after generating.</p>
                @if(file_exists(public_path('sitemap.xml')))
                    <p class="text-xs text-green-600 mt-1.5">Last generated: {{ date('d M Y H:i', filemtime(public_path('sitemap.xml'))) }}</p>
                @else
                    <p class="text-xs text-slate-400 mt-1.5">Not yet generated.</p>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.company-info.sitemap') }}" class="shrink-0">
                @csrf
                <button type="submit"
                        class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">
                    Generate Sitemap
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
