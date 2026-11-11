@extends('admin.layout')
@section('title', 'Application — '.$application->name)
@section('heading', $application->name)

@php
    $size = $application->cv_size >= 1048576
        ? number_format($application->cv_size / 1048576, 1).' MB'
        : max(1, (int) round($application->cv_size / 1024)).' KB';
@endphp

@section('content')
<a href="{{ route('admin.job-applications.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← All applications</a>

<div class="mt-4 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        <div class="bg-white border border-slate-200 rounded-xl p-6">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Applicant</h2>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 text-sm">
                <div><dt class="text-slate-500">Position</dt>
                    <dd class="font-medium text-slate-900">
                        @if($application->vacancy)
                            <a href="{{ route('admin.vacancies.edit', $application->vacancy) }}" class="underline">{{ $application->vacancy->titleIn('en') }}</a>
                        @else
                            {{ $application->vacancy_title ?? 'General application' }}
                        @endif
                    </dd></div>
                <div><dt class="text-slate-500">Department</dt><dd class="text-slate-900">{{ $application->department ? __('site.careers.departments.'.$application->department, [], 'en') : 'Any' }}</dd></div>
                <div><dt class="text-slate-500">Phone</dt><dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $application->phone) }}" class="text-slate-900 underline">{{ $application->phone }}</a></dd></div>
                <div><dt class="text-slate-500">Email</dt><dd>@if($application->email)<a href="mailto:{{ rawurlencode($application->email) }}" class="text-slate-900 underline break-all">{{ $application->email }}</a>@else<span class="text-slate-400">—</span>@endif</dd></div>
                <div><dt class="text-slate-500">Language</dt><dd class="text-slate-900 uppercase">{{ $application->locale }}</dd></div>
                <div><dt class="text-slate-500">Sent</dt><dd class="text-slate-900">{{ $application->created_at->format('d.m.Y H:i') }}</dd></div>
            </dl>
            @if($application->message)
                <h3 class="mt-6 text-sm font-medium text-slate-500">About</h3>
                <p class="mt-1 text-sm text-slate-800 whitespace-pre-line break-words">{{ $application->message }}</p>
            @endif
        </div>

        <div class="bg-white border border-slate-200 rounded-xl p-6 flex flex-wrap items-center gap-4">
            <svg class="w-8 h-8 text-slate-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
            </svg>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-900 break-all">{{ $application->cv_name }}</p>
                <p class="text-xs text-slate-400">{{ $size }} ·
                    @if($application->cv_file_id)
                        stored in the Telegram admin chat
                    @elseif($application->cv_path)
                        on the server — not yet sent to Telegram (retried every hour)
                    @else
                        file missing
                    @endif
                </p>
            </div>
            <a href="{{ route('admin.job-applications.cv', $application) }}"
               class="bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">Download CV</a>
        </div>
    </div>

    <div class="space-y-6">
        <form method="POST" action="{{ route('admin.job-applications.update', $application) }}" class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            @csrf @method('PUT')
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Status</h2>
            <div>
                <label for="status" class="sr-only">Status</label>
                <select id="status" name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
                    @foreach(\App\Models\JobApplication::STATUSES as $key => $label)
                        <option value="{{ $key }}" @selected($application->status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes (staff only)</label>
                <textarea id="notes" name="notes" rows="5" maxlength="5000" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">{{ old('notes', $application->notes) }}</textarea>
            </div>
            <button type="submit" class="w-full bg-slate-900 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-700 transition-colors">Save</button>
        </form>

        <form method="POST" action="{{ route('admin.job-applications.destroy', $application) }}" onsubmit="return confirm('Delete this application and its CV file?')">
            @csrf @method('DELETE')
            <button type="submit" class="text-sm text-slate-400 hover:text-red-600">Delete application and CV</button>
        </form>
    </div>
</div>
@endsection
