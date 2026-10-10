<div class="w-full" x-data="{ locale: 'uz' }">
    <div class="space-y-6">

        <div class="bg-white border border-slate-200 rounded-xl p-6">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Name & Slug</h2>

            {{-- Locale tabs --}}
            <div class="flex gap-1 mb-4 border-b border-slate-200">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                    <button type="button" @click="locale = '{{ $code }}'"
                            :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                            class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                @endforeach
            </div>

            {{-- UZ --}}
            <div x-show="locale === 'uz'" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name (UZ) <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.live.debounce.400ms="nameUz"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('nameUz') border-red-400 @enderror">
                    @error('nameUz') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Slug (UZ)</label>
                        <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <input type="text" wire:model="slugUz" readonly
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-500 font-mono cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">Auto-generated from name.</p>
                </div>
            </div>

            {{-- RU --}}
            <div x-show="locale === 'ru'" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name (RU) <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.live.debounce.400ms="nameRu"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('nameRu') border-red-400 @enderror">
                    @error('nameRu') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Slug (RU)</label>
                        <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <input type="text" wire:model="slugRu" readonly
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-500 font-mono cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">Auto-generated from name.</p>
                </div>
            </div>

            {{-- EN --}}
            <div x-show="locale === 'en'" class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Name (EN) <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.live.debounce.400ms="nameEn"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('nameEn') border-red-400 @enderror">
                    @error('nameEn') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Slug (EN)</label>
                        <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <input type="text" wire:model="slugEn" readonly
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 text-slate-500 font-mono cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">Auto-generated from name.</p>
                </div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Settings</h2>
            <div class="w-24">
                <label class="block text-sm font-medium text-slate-700 mb-1">Sort order</label>
                <input type="number" wire:model="sortOrder" min="0"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">Lower = shown first.</p>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.article-categories.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back</a>
            <button wire:click="save" wire:loading.attr="disabled"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove>{{ $category ? 'Update Category' : 'Create Category' }}</span>
                <span wire:loading>Saving…</span>
            </button>
        </div>

    </div>
</div>
