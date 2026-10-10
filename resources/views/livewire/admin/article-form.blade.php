<div class="w-full" x-data="articleForm(@js($contentUz), @js($contentRu), @js($contentEn))">

    {{-- Sticky save bar --}}
    <div class="sticky top-0 z-10 bg-white border-b border-slate-200 -mx-6 px-6 py-3 mb-6 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M9.401 3.003c1.155-2 4.043-2 5.197 0l7.355 12.748c1.154 2-.29 4.5-2.599 4.5H4.645c-2.309 0-3.752-2.5-2.598-4.5L9.4 3.003zM12 8.25a.75.75 0 01.75.75v3.75a.75.75 0 01-1.5 0V9a.75.75 0 01.75-.75zm0 8.25a.75.75 0 100-1.5.75.75 0 000 1.5z" clip-rule="evenodd"/>
            </svg>
            <span class="text-xs text-slate-500">Unsaved changes</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.articles.index') }}" class="text-sm text-slate-500 hover:text-slate-700 transition-colors">Cancel</a>
            <button @click="syncEditors(); $wire.save()" wire:loading.attr="disabled"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $article ? 'Update Article' : 'Save Article' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>

    <div class="space-y-6">

        {{-- Category & publish settings --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Publishing</h2>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Category <span class="text-red-500">*</span></label>
                <select wire:model="articleCategoryId"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('articleCategoryId') border-red-400 @enderror">
                    <option value="">— Select —</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->getTranslation('name', 'en') }}</option>
                    @endforeach
                </select>
                @error('articleCategoryId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Featured Image</label>
                @if($existingImage)
                    <img src="{{ Storage::url($existingImage) }}" alt="" class="h-24 object-cover rounded-lg mb-2 grayscale">
                @endif
                <input type="file" wire:model="featuredImage" accept="image/*"
                       class="w-full text-sm text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                @error('featuredImage') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                <div wire:loading wire:target="featuredImage" class="text-xs text-slate-400 mt-1">Uploading…</div>
            </div>

            <div class="flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Publish Date</label>
                    <input type="datetime-local" wire:model="publishedAt"
                           class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>
                <div class="flex items-center gap-2 pb-2">
                    <input type="checkbox" wire:model="isPublished" id="isPublished" class="rounded border-slate-300">
                    <label for="isPublished" class="text-sm font-medium text-slate-700 cursor-pointer">Published</label>
                </div>
                <div class="w-24">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Order</label>
                    <input type="number" wire:model="sortOrder" min="0"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>
            </div>
        </div>

        {{-- Translatable title / slug / excerpt / content --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6" x-data="{ locale: 'uz' }">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Content</h2>

            <div class="flex gap-1 mb-5 border-b border-slate-200">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                    <button type="button" @click="locale = '{{ $code }}'"
                            :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                            class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                @endforeach
            </div>

            {{-- UZ --}}
            <div x-show="locale === 'uz'" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Title (UZ) <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.live.debounce.400ms="titleUz"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('titleUz') border-red-400 @enderror">
                    @error('titleUz') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Slug (UZ)</label>
                        <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <input type="text" wire:model="slugUz" readonly
                           class="w-full border border-slate-200 bg-slate-50 text-slate-500 font-mono rounded-lg px-3 py-2 text-sm cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">Auto-generated from title.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Excerpt (UZ)</label>
                    <textarea wire:model="excerptUz" rows="2"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Content (UZ) <span class="text-red-500">*</span></label>
                    @error('contentUz') <p class="text-xs text-red-500 mb-1">{{ $message }}</p> @enderror
                    <div wire:ignore>
                        <div id="quill-uz" class="min-h-[280px] border border-slate-300 rounded-lg"></div>
                    </div>
                </div>
            </div>

            {{-- RU --}}
            <div x-show="locale === 'ru'" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Title (RU) <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.live.debounce.400ms="titleRu"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('titleRu') border-red-400 @enderror">
                    @error('titleRu') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Slug (RU)</label>
                        <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <input type="text" wire:model="slugRu" readonly
                           class="w-full border border-slate-200 bg-slate-50 text-slate-500 font-mono rounded-lg px-3 py-2 text-sm cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">Auto-generated from title.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Excerpt (RU)</label>
                    <textarea wire:model="excerptRu" rows="2"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Content (RU) <span class="text-red-500">*</span></label>
                    @error('contentRu') <p class="text-xs text-red-500 mb-1">{{ $message }}</p> @enderror
                    <div wire:ignore>
                        <div id="quill-ru" class="min-h-[280px] border border-slate-300 rounded-lg"></div>
                    </div>
                </div>
            </div>

            {{-- EN --}}
            <div x-show="locale === 'en'" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Title (EN) <span class="text-red-500">*</span></label>
                    <input type="text" wire:model.live.debounce.400ms="titleEn"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400 @error('titleEn') border-red-400 @enderror">
                    @error('titleEn') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <div class="flex items-center gap-1.5 mb-1">
                        <label class="text-xs font-medium text-slate-500 uppercase tracking-wide">Slug (EN)</label>
                        <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <input type="text" wire:model="slugEn" readonly
                           class="w-full border border-slate-200 bg-slate-50 text-slate-500 font-mono rounded-lg px-3 py-2 text-sm cursor-not-allowed">
                    <p class="text-xs text-slate-400 mt-1">Auto-generated from title.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Excerpt (EN)</label>
                    <textarea wire:model="excerptEn" rows="2"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Content (EN) <span class="text-red-500">*</span></label>
                    @error('contentEn') <p class="text-xs text-red-500 mb-1">{{ $message }}</p> @enderror
                    <div wire:ignore>
                        <div id="quill-en" class="min-h-[280px] border border-slate-300 rounded-lg"></div>
                    </div>
                </div>
            </div>

        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.articles.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back</a>
            <button @click="syncEditors(); $wire.save()" wire:loading.attr="disabled"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors disabled:opacity-60">
                <span wire:loading.remove wire:target="save">{{ $article ? 'Update Article' : 'Create Article' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>

    </div>
</div>

@push('scripts')
<script>
function articleForm(initUz, initRu, initEn) {
    return {
        editors: {},

        init() {
            const uploadUrl = '{{ route('admin.upload.image') }}';
            const token = document.querySelector('meta[name="csrf-token"]')?.content;

            const makeImageHandler = (editor) => {
                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.click();
                input.onchange = async () => {
                    const file = input.files[0];
                    if (!file) return;
                    const form = new FormData();
                    form.append('image', file);
                    const res = await fetch(uploadUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token },
                        body: form,
                    });
                    const { url } = await res.json();
                    const range = editor.getSelection(true);
                    editor.insertEmbed(range.index, 'image', url);
                };
            };

            const toolbarOptions = [
                [{ header: [2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['blockquote', 'code-block'],
                ['link', 'image'],
                ['clean'],
            ];

            const initEditor = (id, initialContent) => {
                const el = document.getElementById(id);
                if (!el) return null;
                const q = new Quill(el, { theme: 'snow', modules: { toolbar: { container: toolbarOptions } } });
                if (initialContent) q.root.innerHTML = initialContent;
                q.getModule('toolbar').addHandler('image', () => makeImageHandler(q));
                return q;
            };

            this.editors.uz = initEditor('quill-uz', initUz);
            this.editors.ru = initEditor('quill-ru', initRu);
            this.editors.en = initEditor('quill-en', initEn);
        },

        syncEditors() {
            if (this.editors.uz) this.$wire.set('contentUz', this.editors.uz.root.innerHTML);
            if (this.editors.ru) this.$wire.set('contentRu', this.editors.ru.root.innerHTML);
            if (this.editors.en) this.$wire.set('contentEn', this.editors.en.root.innerHTML);
        },
    };
}
</script>
@endpush
