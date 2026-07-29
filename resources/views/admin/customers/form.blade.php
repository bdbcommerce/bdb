@extends('admin.layout')
@section('title', $customer->exists ? 'Edit Customer' : 'New Customer')
@section('heading', $customer->exists ? 'Edit Customer' : 'New Customer')

@section('content')
<div class="max-w-2xl">
    <form method="POST"
          action="{{ $customer->exists ? route('admin.customers.update', $customer) : route('admin.customers.store') }}"
          enctype="multipart/form-data" class="space-y-6">
        @csrf
        @if($customer->exists) @method('PUT') @endif

        {{-- Basic Info --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Basic Info</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Company Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $customer->name) }}" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">The official company name (used in all languages).</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Industry</label>
                <select name="industry_id"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <option value="">— None —</option>
                    @foreach($industries as $industry)
                        <option value="{{ $industry->id }}"
                                {{ old('industry_id', $customer->industry_id) == $industry->id ? 'selected' : '' }}>
                            {{ $industry->getTranslation('name', 'en') }}
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400 mt-1">Assign this customer to an industry for filtering on the public site.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Website URL</label>
                <input type="url" name="website" value="{{ old('website', $customer->website) }}" placeholder="https://"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">Optional external link for the customer's logo.</p>
            </div>
        </div>

        {{-- Description --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6" x-data="{ locale: 'uz' }">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Description</h2>
            <div class="flex gap-1 mb-4 border-b border-slate-200">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                    <button type="button" @click="locale = '{{ $code }}'"
                            :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                            class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                @endforeach
            </div>
            @foreach(['uz', 'ru', 'en'] as $locale)
                <div x-show="locale === '{{ $locale }}'">
                    <textarea name="description_{{ $locale }}" rows="3"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">{{ old("description_$locale", $customer->exists ? $customer->getTranslation('description', $locale, false) : '') }}</textarea>
                    <p class="text-xs text-slate-400 mt-1">Short description of this customer ({{ strtoupper($locale) }} version).</p>
                </div>
            @endforeach
        </div>

        {{-- Image & Settings --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Image & Settings</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Logo</label>
                @if($customer->exists && $customer->logo)
                    <div class="mb-3 inline-flex rounded-lg border border-slate-200 p-2 bg-slate-50">
                        <img src="{{ Storage::url($customer->logo) }}" alt="" class="h-12 object-contain grayscale">
                    </div>
                @endif
                <input type="file" name="logo" accept="image/*"
                       class="w-full text-sm text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                <p class="text-xs text-slate-400 mt-1">PNG or SVG with transparent background recommended. Displayed in grayscale on the public site.</p>
            </div>
            <div class="flex gap-4 items-end">
                <div class="w-24">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Sort order</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $customer->sort_order ?? 0) }}" min="0"
                           class="w-24 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    <p class="text-xs text-slate-400 mt-1">Lower = shown first.</p>
                </div>
                <div class="flex items-end pb-6">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1"
                               {{ old('is_active', $customer->is_active ?? true) ? 'checked' : '' }}
                               class="rounded border-slate-300">
                        Active
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.customers.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back</a>
            <button type="submit"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">
                {{ $customer->exists ? 'Update Customer' : 'Create Customer' }}
            </button>
        </div>
    </form>
</div>
@endsection
