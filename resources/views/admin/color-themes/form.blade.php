@extends('admin.layout')
@section('title', $theme->exists ? 'Edit color set' : 'New color set')
@section('heading', $theme->exists ? 'Edit color set' : 'New color set')

@php
    $CT = \App\Models\ColorTheme::class;
    $minContrast = $CT::MIN_CONTRAST;
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $neutralScales = collect($neutrals)->map(fn ($n) => array_map(fn ($v) => "oklch($v)", $n['scale']));
    $suggestions = collect($CT::PRESETS)->pluck('accent')->prepend($CT::DEFAULT['accent'])
        ->merge(['#be123c', '#7c3aed', '#0369a1', '#15803d'])->unique()->values();
    $defaults = collect($groups)->flatMap(fn ($g) => collect($g['fields'])->map(fn ($f) => $f[1]));
    $components = collect(old('components', $theme->customComponents()))->only($defaults->keys())->map(fn ($v) => (string) $v);
@endphp

@section('content')
<form method="POST"
      action="{{ $theme->exists ? route('admin.color-themes.update', $theme) : route('admin.color-themes.store') }}"
      class="grid gap-6 lg:grid-cols-5"
      x-data="colorTheme({
          accent: @js(old('accent', $theme->accent)),
          neutral: @js(old('neutral', $theme->neutral)),
          components: @js((object) $components->all()),
          defaults: @js($defaults),
          neutrals: @js($neutralScales),
          pairs: @js($pairs),
          min: {{ $minContrast }},
      })">
    @csrf
    @if($theme->exists) @method('PUT') @endif

    <div class="lg:col-span-2 space-y-4 min-w-0">
        {{-- Base colours --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-5">
            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Name</label>
                <input id="name" name="name" type="text" required maxlength="60" value="{{ old('name', $theme->name) }}"
                       placeholder="e.g. Spring campaign" class="{{ $input }}">
            </div>

            <div>
                <label for="accent" class="block text-sm font-medium text-slate-700 mb-1">Accent color</label>
                <p class="text-xs text-slate-500 mb-2">The main brand color. Every part set to <b>Auto</b> below is derived from it.</p>
                <div class="flex items-center gap-2">
                    <input type="color" x-model="accent" aria-label="Pick accent color"
                           class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-slate-300 bg-white p-1">
                    <input id="accent" name="accent" type="text" required maxlength="7" x-model="accentText" @input="syncAccent()"
                           class="{{ $input }} font-mono uppercase" placeholder="#1D4ED8">
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5" aria-label="Suggested accents">
                    @foreach($suggestions as $hex)
                        <button type="button" @click="setAccent('{{ $hex }}')" title="{{ strtoupper($hex) }}"
                                class="size-7 rounded-md border border-white ring-1 ring-slate-200 hover:ring-slate-500 focus-visible:outline-2 focus-visible:outline-slate-900"
                                :class="accent === '{{ $hex }}' && 'ring-2 ring-slate-900'"
                                style="background: {{ $hex }}"><span class="sr-only">{{ $hex }}</span></button>
                    @endforeach
                </div>
                <p class="mt-2 text-xs" :class="accentOk ? 'text-slate-500' : 'text-red-600'">
                    White text on the accent: <b x-text="accentRatio + ':1'"></b>
                    <span x-show="accentOk">— readable</span>
                    <span x-show="!accentOk">— too light, choose a darker color (needs {{ $minContrast }}:1)</span>
                </p>
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-slate-700 mb-2">Neutral tone (text, borders, backgrounds)</legend>
                <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                    @foreach($neutrals as $key => $n)
                        <label class="flex items-center gap-2.5 rounded-lg border px-3 py-2 cursor-pointer has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-slate-400"
                               :class="neutral === '{{ $key }}' ? 'border-slate-900 bg-slate-50' : 'border-slate-200 hover:border-slate-400'">
                            <input type="radio" name="neutral" value="{{ $key }}" x-model="neutral" class="sr-only">
                            <span class="flex overflow-hidden rounded" aria-hidden="true">
                                @foreach([1, 3, 5, 7, 9] as $i)
                                    <span class="size-3.5" style="background: oklch({{ $n['scale'][$i] }})"></span>
                                @endforeach
                            </span>
                            <span class="text-xs text-slate-700">{{ $n['label'] }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </div>

        {{-- Per-component colours --}}
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
            <div class="px-6 pt-5 pb-3">
                <h2 class="text-sm font-semibold text-slate-900">Site parts</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Fine-tune individual parts. <b>Auto</b> follows the accent and neutral tone; pick a color to override it.
                    <span x-show="customCount" x-text="'· ' + customCount + ' changed'"></span>
                </p>
            </div>
            @foreach($groups as $gKey => $group)
                @php $keys = array_keys($group['fields']); @endphp
                <div class="border-t border-slate-100" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" :aria-expanded="open.toString()"
                            class="flex w-full items-center gap-3 px-6 py-3 text-left hover:bg-slate-50">
                        <span class="flex -space-x-1" aria-hidden="true">
                            @foreach($keys as $key)
                                <span class="size-4 rounded-full ring-2 ring-white border border-slate-200" :style="`background:${color('{{ $key }}')}`"></span>
                            @endforeach
                        </span>
                        <span class="flex-1 text-sm font-medium text-slate-800">{{ $group['label'] }}</span>
                        <span class="text-xs text-slate-400" x-text="groupCount(@js($keys)) ? groupCount(@js($keys)) + ' changed' : 'Auto'"></span>
                        <svg class="size-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                        </svg>
                    </button>
                    <div x-show="open" x-cloak class="px-6 pb-4 space-y-2.5">
                        @foreach($group['fields'] as $key => [$label, $default])
                            <div class="flex items-center gap-2">
                                <label for="c-{{ $key }}" class="flex-1 min-w-0 text-xs text-slate-700">{{ $label }}</label>
                                <input type="color" :value="hexOf('{{ $key }}')" @input="components['{{ $key }}'] = $event.target.value"
                                       aria-label="Pick: {{ $group['label'] }} — {{ $label }}"
                                       class="h-8 w-9 shrink-0 cursor-pointer rounded-md border border-slate-300 bg-white p-0.5">
                                <input id="c-{{ $key }}" type="text" name="components[{{ $key }}]" maxlength="7"
                                       x-model="components['{{ $key }}']" placeholder="Auto"
                                       class="w-24 shrink-0 border border-slate-300 rounded-md px-2 py-1.5 text-xs font-mono uppercase focus:outline-none focus:ring-2 focus:ring-slate-400 placeholder:normal-case placeholder:text-slate-400">
                                <button type="button" @click="components['{{ $key }}'] = ''" x-show="components['{{ $key }}']"
                                        class="w-9 shrink-0 text-xs text-slate-500 underline hover:text-slate-900">Auto</button>
                                <span x-show="!components['{{ $key }}']" class="w-9 shrink-0" aria-hidden="true"></span>
                            </div>
                        @endforeach
                        @if($gKey === 'header')
                            <p class="text-xs text-slate-400">Tip: the logo has a white background, so a light header looks best.</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Readability --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6">
            <h2 class="text-sm font-semibold text-slate-900 mb-2">Readability</h2>
            <ul class="space-y-1 text-xs">
                <template x-for="p in checks" :key="p.label">
                    <li class="flex items-center gap-2" :class="p.blocking ? 'text-red-600 font-medium' : 'text-slate-600'">
                        <span class="inline-flex size-4 items-center justify-center rounded-full border text-[10px]" :class="p.blocking ? 'border-red-600' : 'border-slate-300'" aria-hidden="true" x-text="p.ok ? '✓' : '!'"></span>
                        <span class="flex-1" x-text="p.label + (!p.ok && !p.blocking ? ' (Auto — slightly below target)' : '')"></span>
                        <span class="font-mono" x-text="p.ratio + ':1'"></span>
                    </li>
                </template>
            </ul>
            <p class="text-xs text-slate-400 mt-2">Text needs at least {{ $minContrast }}:1 against its background (WCAG AA). Colors you change must pass; Auto pairs are shown for information.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="activate" value="1" @checked(old('activate', $theme->is_active))>
                Use on the site after saving
            </label>
            <div class="flex flex-wrap items-center gap-2">
                <button class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="!allOk">Save</button>
                <a href="{{ route('admin.color-themes.index') }}" class="border border-slate-300 rounded-lg px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Cancel</a>
                <button type="button" @click="resetParts()" x-show="customCount" class="ml-auto text-xs text-slate-500 underline hover:text-slate-900">Set all parts to Auto</button>
            </div>
            <p x-show="!allOk" class="text-xs text-red-600">Fix the colors marked in red to save.</p>
        </div>
    </div>

    {{-- Live preview: the same mock as the gallery, coloured through CSS variables --}}
    <div class="lg:col-span-3 min-w-0">
        <div class="lg:sticky lg:top-6 space-y-4">
            <div class="rounded-xl border border-slate-200 bg-(color:--color-gray-50) p-4 sm:p-6" :style="vars">
                <p class="mb-3 text-xs font-medium text-slate-500">Live preview</p>
                @include('admin.color-themes.mock')
            </div>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <p class="text-xs font-medium text-slate-500 mb-2">Accent shades</p>
                <div class="grid grid-cols-11 overflow-hidden rounded-lg">
                    @foreach($shades as $shade)
                        <div class="h-8" :style="`background:${s({{ $shade }})}`" :title="s({{ $shade }})"></div>
                    @endforeach
                </div>
                <div class="grid grid-cols-11 mt-1 text-center text-[10px] text-slate-400">
                    @foreach($shades as $shade)<span>{{ $shade }}</span>@endforeach
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    function colorTheme(cfg) {
        // Keep in sync with App\Models\ColorTheme::MIX / GRAY_HEX.
        const MIX = {50: ['#ffffff', .94], 100: ['#ffffff', .88], 200: ['#ffffff', .76], 300: ['#ffffff', .58],
            400: ['#ffffff', .34], 500: ['#ffffff', .15], 600: null,
            700: ['#000000', .18], 800: ['#000000', .34], 900: ['#000000', .46], 950: ['#000000', .66]};
        const GRAY_HEX = {50: '#f9fafb', 100: '#f3f4f6', 200: '#e5e7eb', 300: '#d1d5db', 400: '#9ca3af',
            500: '#6b7280', 600: '#4b5563', 700: '#374151', 800: '#1f2937', 900: '#111827', 950: '#030712'};
        const SHADES = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
        const valid = h => /^#[0-9a-f]{6}$/.test(String(h || '').trim().toLowerCase());
        const rgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
        const lum = h => { const L = rgb(h).map(c => { c /= 255; return c <= .03928 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4; });
            return .2126 * L[0] + .7152 * L[1] + .0722 * L[2]; };
        const contrast = (a, b) => { const x = lum(a), y = lum(b); return Math.round((Math.max(x, y) + .05) / (Math.min(x, y) + .05) * 100) / 100; };

        const components = {};
        Object.keys(cfg.defaults).forEach(k => components[k] = cfg.components[k] || '');

        return {
            accent: valid(cfg.accent) ? cfg.accent.toLowerCase() : '#dc2626', accentText: cfg.accent || '', neutral: cfg.neutral, components,
            init() { this.$watch('accent', v => { if (this.accentText.toLowerCase() !== v) this.accentText = v; }); },
            setAccent(h) { this.accent = h; this.accentText = h; },
            syncAccent() { const h = this.accentText.trim().toLowerCase(); if (valid(h)) this.accent = h; },
            resetParts() { Object.keys(this.components).forEach(k => this.components[k] = ''); },

            s(shade) {
                const m = MIX[shade]; if (!m) return this.accent;
                const a = rgb(this.accent), b = rgb(m[0]);
                return '#' + a.map((c, i) => Math.round(c + (b[i] - c) * m[1]).toString(16).padStart(2, '0')).join('');
            },
            g(shade) { return (cfg.neutrals[this.neutral] || cfg.neutrals.gray)[SHADES.indexOf(shade)]; },
            custom(key) { const v = String(this.components[key] || '').trim().toLowerCase(); return valid(v) ? v : null; },
            // exact CSS colour of a part (oklch for greys) / its #hex (for the picker and contrast maths)
            color(key) { return this.custom(key) || this.resolve(cfg.defaults[key], true); },
            hexOf(key) { return this.custom(key) || this.resolve(cfg.defaults[key], false); },
            resolve(spec, exact) {
                if (spec.startsWith('#')) return spec;
                const [family, shade] = spec.split(':');
                if (family === 'brand') return this.s(+shade);
                return exact ? this.g(+shade) : GRAY_HEX[shade];
            },
            groupCount(keys) { return keys.filter(k => this.custom(k)).length; },
            get customCount() { return this.groupCount(Object.keys(this.components)); },

            get vars() {
                const v = {};
                SHADES.forEach(sh => { v['--color-brand-' + sh] = this.s(sh); v['--color-gray-' + sh] = this.g(sh); });
                Object.keys(cfg.defaults).forEach(k => v['--site-' + k] = this.color(k));
                return v;
            },
            get accentRatio() { return contrast(this.accent, '#ffffff'); },
            get accentOk() { return valid(this.accentText) && this.accentRatio >= cfg.min; },
            get checks() {
                return cfg.pairs.map(([text, bg, label]) => {
                    const a = this.hexOf(text), b = bg.startsWith('#') ? bg : this.hexOf(bg);
                    const ratio = contrast(a, b);
                    // automatic pairs are informational; customised ones must pass (same rule as the server)
                    const customised = this.custom(text) || (!bg.startsWith('#') && this.custom(bg));
                    return {label, ratio, ok: ratio >= cfg.min, blocking: ratio < cfg.min && !!customised};
                });
            },
            get badInput() { return Object.values(this.components).some(v => String(v).trim() !== '' && !valid(v)); },
            get allOk() { return this.accentOk && !this.badInput && !this.checks.some(c => c.blocking); },
        };
    }
</script>
@endsection
