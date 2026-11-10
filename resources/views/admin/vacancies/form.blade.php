@extends('admin.layout')
@section('title', $vacancy->exists ? 'Edit vacancy' : 'New vacancy')
@section('heading', $vacancy->exists ? 'Edit vacancy' : 'New vacancy')

@php
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $tr = fn (string $field, string $l) => old("{$field}_$l", $vacancy->exists ? $vacancy->getTranslation($field, $l, false) : '');
@endphp

@section('content')
<div class="w-full">
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ $vacancy->exists ? route('admin.vacancies.update', $vacancy) : route('admin.vacancies.store') }}" class="space-y-6">
        @csrf
        @if($vacancy->exists) @method('PUT') @endif

        <div class="bg-white border border-slate-200 rounded-xl p-6" x-data="{ locale: 'uz' }">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Text</h2>
            <div class="flex gap-1 mb-4 border-b border-slate-200">
                @foreach(['uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN'] as $code => $label)
                    <button type="button" @click="locale = '{{ $code }}'"
                            :class="locale === '{{ $code }}' ? 'border-b-2 border-slate-900 text-slate-900 font-medium' : 'text-slate-500 hover:text-slate-700'"
                            class="px-4 py-2 text-sm -mb-px transition-colors">{{ $label }}</button>
                @endforeach
            </div>
            @foreach(['uz', 'ru', 'en'] as $l)
                <div x-show="locale === '{{ $l }}'" @if($l !== 'uz') x-cloak @endif class="space-y-4">
                    <div>
                        <label for="title_{{ $l }}" class="block text-sm font-medium text-slate-700 mb-1">Job title ({{ strtoupper($l) }}) @if($l === 'uz')<span class="text-red-500">*</span>@endif</label>
                        <input id="title_{{ $l }}" type="text" name="title_{{ $l }}" maxlength="200" value="{{ $tr('title', $l) }}" class="{{ $input }}">
                        @if($l !== 'uz')<p class="text-xs text-slate-400 mt-1">If empty, the vacancy is not shown on the {{ strtoupper($l) }} page.</p>@endif
                    </div>
                    <div>
                        <label for="location_{{ $l }}" class="block text-sm font-medium text-slate-700 mb-1">Location ({{ strtoupper($l) }})</label>
                        <input id="location_{{ $l }}" type="text" name="location_{{ $l }}" maxlength="200" value="{{ $tr('location', $l) }}" placeholder="e.g. Tashkent" class="{{ $input }}">
                    </div>
                    <div>
                        <label for="description_{{ $l }}" class="block text-sm font-medium text-slate-700 mb-1">About the job ({{ strtoupper($l) }})</label>
                        <textarea id="description_{{ $l }}" name="description_{{ $l }}" rows="4" class="{{ $input }}">{{ $tr('description', $l) }}</textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="requirements_{{ $l }}" class="block text-sm font-medium text-slate-700 mb-1">Requirements ({{ strtoupper($l) }})</label>
                            <textarea id="requirements_{{ $l }}" name="requirements_{{ $l }}" rows="6" class="{{ $input }}">{{ $tr('requirements', $l) }}</textarea>
                            <p class="text-xs text-slate-400 mt-1">One item per line.</p>
                        </div>
                        <div>
                            <label for="offer_{{ $l }}" class="block text-sm font-medium text-slate-700 mb-1">We offer ({{ strtoupper($l) }})</label>
                            <textarea id="offer_{{ $l }}" name="offer_{{ $l }}" rows="6" class="{{ $input }}">{{ $tr('offer', $l) }}</textarea>
                            <p class="text-xs text-slate-400 mt-1">One item per line.</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Settings</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label for="department" class="block text-sm font-medium text-slate-700 mb-1">Department <span class="text-red-500">*</span></label>
                    <select id="department" name="department" class="{{ $input }}">
                        @foreach(\App\Models\Vacancy::DEPARTMENTS as $dep)
                            <option value="{{ $dep }}" @selected(old('department', $vacancy->department) === $dep)>{{ __('site.careers.departments.'.$dep, [], 'en') }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="employment_type" class="block text-sm font-medium text-slate-700 mb-1">Employment type</label>
                    <select id="employment_type" name="employment_type" class="{{ $input }}">
                        @foreach(\App\Models\Vacancy::EMPLOYMENT_TYPES as $type)
                            <option value="{{ $type }}" @selected(old('employment_type', $vacancy->employment_type) === $type)>{{ __('site.careers.types.'.$type, [], 'en') }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="salary" class="block text-sm font-medium text-slate-700 mb-1">Salary</label>
                    <input id="salary" type="text" name="salary" maxlength="120" value="{{ old('salary', $vacancy->salary) }}" placeholder="e.g. from 8 000 000 UZS" class="{{ $input }}">
                    <p class="text-xs text-slate-400 mt-1">Optional, shown as written in every language.</p>
                </div>
            </div>
            <div class="flex gap-6 items-end">
                <div>
                    <label for="sort_order" class="block text-sm font-medium text-slate-700 mb-1">Sort order</label>
                    <input id="sort_order" type="number" name="sort_order" min="0" value="{{ old('sort_order', $vacancy->sort_order ?? 0) }}" class="w-24 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400">
                </div>
                <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer pb-2">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $vacancy->is_active ?? true)) class="rounded border-slate-300">
                    Active (shown on the Careers page)
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between">
            <a href="{{ route('admin.vacancies.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back</a>
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-5 py-2.5 hover:bg-slate-700 transition-colors">
                {{ $vacancy->exists ? 'Update vacancy' : 'Create vacancy' }}
            </button>
        </div>
    </form>
</div>
@endsection
