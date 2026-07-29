@extends('admin.layout')
@section('title', $industry->exists ? 'Edit Industry' : 'New Industry')
@section('heading', $industry->exists ? 'Edit Industry' : 'New Industry')

@section('content')
<div class="max-w-2xl">
    <form method="POST"
          action="{{ $industry->exists ? route('admin.industries.update', $industry) : route('admin.industries.store') }}"
          class="space-y-6">
        @csrf
        @if($industry->exists) @method('PUT') @endif

        {{-- Basic Info --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6" x-data="{ locale: 'uz' }">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Basic Info</h2>
            <div class="flex gap-1 mb-4 border-b border-slate-200">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                    <button type="button" @click="locale = '{{ $code }}'"
                            :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                            class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                @endforeach
            </div>
            @foreach(['uz', 'ru', 'en'] as $locale)
                <div x-show="locale === '{{ $locale }}'" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Name ({{ strtoupper($locale) }}) <span class="text-red-500">*</span></label>
                        <input type="text" name="name_{{ $locale }}"
                               value="{{ old("name_$locale", $industry->exists ? $industry->getTranslation('name', $locale, false) : '') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                        <p class="text-xs text-slate-400 mt-1">The industry name displayed in the customer filter ({{ strtoupper($locale) }} version).</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Description ({{ strtoupper($locale) }})</label>
                        <textarea name="description_{{ $locale }}" rows="3"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">{{ old("description_$locale", $industry->exists ? $industry->getTranslation('description', $locale, false) : '') }}</textarea>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Settings --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Settings</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Icon name</label>
                <input type="text" name="icon" value="{{ old('icon', $industry->icon) }}"
                       placeholder="e.g. building-office"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">Enter a Heroicon name slug, e.g. <code class="bg-slate-100 px-1 rounded">building-office</code>, <code class="bg-slate-100 px-1 rounded">truck</code>, <code class="bg-slate-100 px-1 rounded">chart-bar</code>. Browse all icons at <span class="font-mono">heroicons.com</span>.</p>
            </div>
            <div class="flex gap-4 items-end">
                <div class="w-24">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Sort order</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $industry->sort_order ?? 0) }}" min="0"
                           class="w-24 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <p class="text-xs text-slate-400 mt-1">Lower = shown first.</p>
                </div>
                <div class="flex items-end pb-6">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1"
                               {{ old('is_active', $industry->is_active ?? true) ? 'checked' : '' }}
                               class="rounded border-slate-300">
                        Active
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.industries.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back</a>
            <button type="submit"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">
                {{ $industry->exists ? 'Update Industry' : 'Create Industry' }}
            </button>
        </div>
    </form>
</div>
@endsection
