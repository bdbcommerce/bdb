@extends('admin.layout')
@section('title', $entry->exists ? 'Edit Knowledge Entry' : 'New Knowledge Entry')
@section('heading', $entry->exists ? 'Edit Knowledge Entry' : 'New Knowledge Entry')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <form method="POST"
          action="{{ $entry->exists ? route('admin.knowledge.update', $entry) : route('admin.knowledge.store') }}"
          class="space-y-6 lg:col-span-2">
        @csrf
        @if($entry->exists) @method('PUT') @endif

        {{-- Topic --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">1. Topic</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Type <span class="text-red-500">*</span></label>
                <select name="type"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                    @foreach(\App\Models\KnowledgeEntry::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('type', $entry->type ?? 'faq') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-400 mt-1">Groups related facts together so the assistant finds them faster.</p>
            </div>
        </div>

        {{-- Q&A --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6" x-data="{ locale: 'uz' }">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">2. Question &amp; answer</h2>
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
                        <label class="block text-sm font-medium text-slate-700 mb-1">Question ({{ strtoupper($locale) }}) <span class="text-red-500">*</span></label>
                        <input type="text" name="question_{{ $locale }}"
                               value="{{ old("question_$locale", $entry->exists ? $entry->getTranslation('question', $locale, false) : '') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                        <p class="text-xs text-slate-400 mt-1">Write it the way a customer would ask it.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Answer ({{ strtoupper($locale) }}) <span class="text-red-500">*</span></label>
                        <textarea name="answer_{{ $locale }}" rows="6"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">{{ old("answer_$locale", $entry->exists ? $entry->getTranslation('answer', $locale, false) : '') }}</textarea>
                        <p class="text-xs text-slate-400 mt-1">Facts only. The assistant will say this and nothing more.</p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Hints --}}
        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">3. Hints &amp; settings</h2>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Keywords / synonyms</label>
                <input type="text" name="keywords" value="{{ old('keywords', $entry->keywords) }}"
                       placeholder="kafolat, гарантия, warranty, ремонт"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">Comma-separated, any language. Helps match differently worded questions.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Related link</label>
                <input type="url" name="link" value="{{ old('link', $entry->link) }}" placeholder="https://"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                <p class="text-xs text-slate-400 mt-1">Optional page the assistant can point visitors to.</p>
            </div>
            <div class="flex gap-4 items-end">
                <div class="w-24">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Sort order</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', $entry->sort_order ?? 0) }}" min="0"
                           class="w-24 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>
                <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer pb-2">
                    <input type="checkbox" name="is_active" value="1"
                           {{ old('is_active', $entry->is_active ?? true) ? 'checked' : '' }}
                           class="rounded border-slate-300">
                    Active (assistant can use it)
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.knowledge.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back</a>
            <button type="submit"
                    class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">
                {{ $entry->exists ? 'Update Entry' : 'Create Entry' }}
            </button>
        </div>
    </form>

    {{-- Writing guide --}}
    <aside class="bg-white border border-slate-200 rounded-xl p-6 h-fit text-sm text-slate-600 space-y-3">
        <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Writing for the AI</h2>
        <ul class="space-y-2 list-disc pl-4">
            <li><strong>One topic per entry.</strong> "Warranty period" and "How to return a device" are two entries.</li>
            <li><strong>Be specific.</strong> "12 months on scanners, 6 months on printheads" beats "we offer warranty".</li>
            <li><strong>Say what is not offered</strong> too, e.g. "We do not deliver outside Uzbekistan."</li>
            <li><strong>No prices you can't keep.</strong> If prices vary, write "Prices depend on volume; leave your phone for a quote."</li>
            <li>Changes reach the chat <strong>immediately</strong> after saving.</li>
        </ul>
        <div class="border-t border-slate-100 pt-3">
            <p class="text-xs font-semibold text-slate-500 mb-1">Example</p>
            <p class="text-xs"><span class="text-slate-400">Q:</span> Do you help with Asl Belgi setup?</p>
            <p class="text-xs"><span class="text-slate-400">A:</span> Yes. We set up scanners and label printers for marking and help connect them to the Asl Belgi system. Contact our team for an on-site visit.</p>
        </div>
    </aside>
</div>
@endsection
