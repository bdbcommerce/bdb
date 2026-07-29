<div class="space-y-6">

    {{-- Sticky save bar --}}
    <div class="sticky top-0 z-10 bg-white border-b border-slate-200 -mx-6 px-6 py-3 mb-2 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M9.401 3.003c1.155-2 4.043-2 5.197 0l7.355 12.748c1.154 2-.29 4.5-2.599 4.5H4.645c-2.309 0-3.752-2.5-2.598-4.5L9.4 3.003zM12 8.25a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V9a.75.75 0 01.75-.75zm0 8.25a.75.75 0 100-1.5.75.75 0 000 1.5z" clip-rule="evenodd"/>
            </svg>
            <span class="text-xs text-slate-500">Unsaved changes</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.banners.index') }}" class="text-sm text-slate-500 hover:text-slate-700 transition-colors">Cancel</a>
            <button type="button" wire:click="save" wire:loading.attr="disabled"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors disabled:opacity-50">
                <span wire:loading.remove>{{ $banner ? 'Update Banner' : 'Save Banner' }}</span>
                <span wire:loading>Saving…</span>
            </button>
        </div>
    </div>

    {{-- Locale tabs for title/subtitle/button text --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-5" x-data="{ locale: 'uz' }">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Content</h2>

        <div class="flex gap-1 border-b border-slate-200">
            @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                <button type="button" @click="locale = '{{ $code }}'"
                        :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                        class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
            @endforeach
        </div>

        {{-- Title --}}
        @foreach(['uz', 'ru', 'en'] as $lc)
            <div x-show="locale === '{{ $lc }}'">
                <label class="block text-xs font-medium text-slate-500 mb-1">Title <span class="uppercase text-slate-400">({{ $lc }})</span> *</label>
                <input type="text"
                       wire:model="title{{ ucfirst($lc) }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                       placeholder="Banner heading ({{ strtoupper($lc) }})">
                @error("title".ucfirst($lc)) <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        @endforeach

        {{-- Subtitle --}}
        @foreach(['uz', 'ru', 'en'] as $lc)
            <div x-show="locale === '{{ $lc }}'">
                <label class="block text-xs font-medium text-slate-500 mb-1">Subtitle <span class="uppercase text-slate-400">({{ $lc }})</span></label>
                <textarea wire:model="subtitle{{ ucfirst($lc) }}" rows="2"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                          placeholder="Short description under the title ({{ strtoupper($lc) }})"></textarea>
            </div>
        @endforeach

        {{-- Button text --}}
        @foreach(['uz', 'ru', 'en'] as $lc)
            <div x-show="locale === '{{ $lc }}'">
                <label class="block text-xs font-medium text-slate-500 mb-1">Button label <span class="uppercase text-slate-400">({{ $lc }})</span></label>
                <input type="text"
                       wire:model="buttonText{{ ucfirst($lc) }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"
                       placeholder="e.g. Learn more / Подробнее / Batafsil">
            </div>
        @endforeach
    </div>

    {{-- Button URL --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Button Link</h2>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">URL</label>
            <input type="url" wire:model="buttonUrl"
                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-slate-400"
                   placeholder="https://example.com/page">
            @error('buttonUrl') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            <p class="text-xs text-slate-400 mt-1">Leave empty to show no button. Relative paths like /uz/brands also work.</p>
        </div>
    </div>

    {{-- Image --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Image</h2>
        <p class="text-xs text-slate-400">Recommended: 1600×700 px, JPEG or PNG, max 4 MB. Will be shown full-width.</p>

        @if($existingImage && !$image)
            <div class="relative inline-block">
                <img src="{{ Storage::url($existingImage) }}" alt="Current" class="h-32 rounded-lg object-cover border border-slate-200">
                <p class="text-xs text-slate-400 mt-1">Current image</p>
            </div>
        @endif

        @if($image)
            <div>
                <img src="{{ $image->temporaryUrl() }}" alt="Preview" class="h-32 rounded-lg object-cover border border-slate-200">
                <p class="text-xs text-slate-400 mt-1">New image preview</p>
            </div>
        @endif

        <label class="block">
            <input type="file" wire:model="image" accept="image/*"
                   class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200 cursor-pointer">
        </label>
        @error('image') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
    </div>

    {{-- Settings --}}
    <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Settings</h2>
        <div class="flex flex-wrap gap-6">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                <span class="text-sm font-medium text-slate-700">Active</span>
            </label>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Sort order</label>
                <input type="number" wire:model="sortOrder" min="0"
                       class="w-24 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">Lower = shown first</p>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.banners.index') }}" class="text-sm text-slate-500 hover:text-slate-700 transition-colors">← Cancel</a>
        <button type="button" wire:click="save" wire:loading.attr="disabled"
                class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors disabled:opacity-50">
            <span wire:loading.remove>{{ $banner ? 'Update Banner' : 'Create Banner' }}</span>
            <span wire:loading>Saving…</span>
        </button>
    </div>

</div>
