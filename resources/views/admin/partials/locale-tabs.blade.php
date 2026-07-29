{{-- Usage: @include('admin.partials.locale-tabs', ['active' => 'uz']) --}}
@php $active ??= 'uz'; @endphp
<div x-data="{ locale: '{{ $active }}' }">
    <div class="flex gap-1 mb-4 border-b border-slate-200">
        @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
            <button type="button"
                    @click="locale = '{{ $code }}'"
                    :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                    class="px-4 py-2 text-sm transition-colors -mb-px">
                {{ $label }}
            </button>
        @endforeach
    </div>
    {{ $slot }}
</div>
