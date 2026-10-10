{{-- Graceful empty state. Params: $icon, $title, $text, $assistant (bool, default true). --}}
<div class="relative overflow-hidden rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center" x-data>
    <div class="absolute inset-0 bg-grid mask-fade pointer-events-none opacity-60" aria-hidden="true"></div>
    <div class="relative mx-auto max-w-md">
        <span class="icon-tile size-12 mx-auto text-gray-500">
            @include('site.partials.icon', ['name' => $icon ?? 'cube', 'class' => 'size-6'])
        </span>
        <p class="mt-5 text-base font-semibold text-gray-900">{{ $title }}</p>
        @if(! empty($text))
            <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ $text }}</p>
        @endif
        @if($assistant ?? true)
            <div class="mt-6 flex flex-col sm:flex-row justify-center gap-3">
                <button type="button" @click="$dispatch('belgi-open')" class="btn-secondary !py-2.5">
                    @include('site.partials.icon', ['name' => 'sparkles', 'class' => 'size-4 text-red-600'])
                    {{ __('site.cta.ask_ai') }}
                </button>
                <a href="{{ route('site.contact', app()->getLocale()) }}" class="btn-primary !py-2.5">{{ __('Contact us') }}</a>
            </div>
        @endif
    </div>
</div>
