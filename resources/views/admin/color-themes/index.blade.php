@extends('admin.layout')
@section('title', 'Site colors')
@section('heading', 'Site colors')

@section('header-actions')
    <a href="{{ route('admin.color-themes.create') }}"
       class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">New color set</a>
@endsection

@section('content')
<p class="text-xs text-slate-500 max-w-2xl mb-4">
    Choose the colors of the public website. The <b>accent</b> colors buttons, links and highlights; the <b>neutral</b>
    tone colors text, borders and backgrounds. <b>Preview</b> opens the site in that theme for you only.
    The default theme is the original BDB look and can always be restored. The admin panel keeps its own colors.
</p>

<ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" role="list">
    @foreach($themes as $theme)
        @php $scale = $theme->accentScale(); @endphp
        <li class="bg-white rounded-xl overflow-hidden flex flex-col border {{ $theme->is_active ? "border-slate-900 ring-1 ring-slate-900" : "border-slate-200" }}">
            {{-- Mini preview of the site in this theme --}}
            <div class="p-4 border-b border-slate-100 bg-(color:--color-gray-50)" style="{{ $theme->previewStyle() }}">
                @include("admin.color-themes.mock", ["compact" => true])
                <div class="mt-3 flex overflow-hidden rounded-md" aria-hidden="true">
                    @foreach([100, 300, 500, 600, 700, 900] as $shade)
                        <span class="h-2 flex-1" style="background: {{ $scale[$shade] }}"></span>
                    @endforeach
                </div>
            </div>
            <div class="p-4 flex-1 flex flex-col gap-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-slate-900 truncate">{{ $theme->name }}</h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            <span class="font-mono uppercase">{{ $theme->accent }}</span>
                            · {{ strtok(\App\Models\ColorTheme::NEUTRALS[$theme->neutral]['label'], ' ') }}
                            · contrast {{ $theme->accentContrast() }}:1
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        @if($theme->is_locked)
                            <span class="text-[11px] font-medium px-2 py-0.5 rounded-full border border-slate-200 text-slate-500">Default</span>
                        @endif
                        @if($theme->is_active)
                            <span class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-slate-900 text-white">Live</span>
                        @endif
                    </div>
                </div>

                <div class="mt-auto flex flex-wrap items-center gap-2 text-xs">
                    @unless($theme->is_active)
                        <form method="POST" action="{{ route('admin.color-themes.activate', $theme) }}">
                            @csrf
                            <button class="bg-slate-900 text-white font-medium rounded-lg px-3 py-1.5 hover:bg-slate-700">Use on site</button>
                        </form>
                    @endunless
                    <a href="{{ route('site.home', ['locale' => 'uz', 'theme_preview' => $theme->id]) }}" target="_blank" rel="noopener"
                       class="border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Preview</a>
                    @unless($theme->is_locked)
                        <a href="{{ route('admin.color-themes.edit', $theme) }}" class="border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Edit</a>
                    @endunless
                    <a href="{{ route('admin.color-themes.create', ['from' => $theme->id]) }}" class="border border-slate-300 rounded-lg px-3 py-1.5 text-slate-700 hover:bg-slate-50">Duplicate</a>
                    @unless($theme->is_locked || $theme->is_active)
                        <form method="POST" action="{{ route('admin.color-themes.destroy', $theme) }}" class="ml-auto"
                              onsubmit="return confirm('Delete this color set?')">
                            @csrf @method('DELETE')
                            <button class="text-slate-400 hover:text-red-600">Delete</button>
                        </form>
                    @endunless
                </div>
            </div>
        </li>
    @endforeach
</ul>
@endsection
