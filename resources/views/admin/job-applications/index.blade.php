@extends('admin.layout')
@section('title', 'Applications (CVs)')
@section('heading', 'Applications (CVs)')

@php
    $statusClass = fn (string $s) => match ($s) {
        'new' => 'bg-amber-50 text-amber-700', 'interview' => 'bg-sky-50 text-sky-700',
        'hired' => 'bg-green-50 text-green-700', 'rejected' => 'bg-slate-100 text-slate-500', default => 'bg-slate-100 text-slate-700',
    };
@endphp

@section('content')
<p class="text-xs text-slate-500 mb-3">CVs sent from the public Careers page. Files are private and kept for 365 days.</p>

<form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
    <div class="flex flex-wrap gap-1">
        <a href="{{ route('admin.job-applications.index', array_filter(['vacancy' => $vacancyId])) }}"
           class="text-xs font-medium px-3 py-1.5 rounded-full border {{ ! $status ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400' }}">
            All <span class="tabular-nums">{{ $counts->sum() }}</span>
        </a>
        @foreach(\App\Models\JobApplication::STATUSES as $key => $label)
            <a href="{{ route('admin.job-applications.index', array_filter(['status' => $key, 'vacancy' => $vacancyId])) }}"
               class="text-xs font-medium px-3 py-1.5 rounded-full border {{ $status === $key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400' }}">
                {{ $label }} <span class="tabular-nums">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div>
    @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <label for="vacancy-filter" class="sr-only">Vacancy</label>
    <select id="vacancy-filter" name="vacancy" onchange="this.form.submit()" class="ml-auto border border-slate-300 rounded-lg px-3 py-1.5 text-sm bg-white">
        <option value="">All vacancies</option>
        <option value="none" @selected($vacancyId === 'none')>General applications</option>
        @foreach($vacancies as $v)
            <option value="{{ $v->id }}" @selected((string) $vacancyId === (string) $v->id)>{{ $v->titleIn('en') }}</option>
        @endforeach
    </select>
</form>

<div class="bg-white border border-slate-200 rounded-xl overflow-hidden">
    @if($applications->isEmpty())
        <div class="px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-500">No applications</p>
            <p class="text-xs text-slate-400 mt-1">When someone sends a CV on the Careers page, it appears here (and in Telegram, if alerts are set up).</p>
        </div>
    @else
        <ul class="divide-y divide-slate-100">
            @foreach($applications as $application)
                <li>
                    <a href="{{ route('admin.job-applications.show', $application) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4 hover:bg-slate-50">
                        <span class="font-medium text-slate-900 {{ $application->status === 'new' ? '' : 'font-normal' }}">{{ $application->name }}</span>
                        <span class="text-sm text-slate-600">{{ $application->phone }}</span>
                        <span class="text-sm text-slate-500 min-w-0 truncate">
                            {{ $application->vacancy?->titleIn('en') ?? $application->vacancy_title ?? 'General application' }}@if($application->department) · {{ __('site.careers.departments.'.$application->department, [], 'en') }}@endif
                        </span>
                        <span class="ml-auto flex items-center gap-3">
                            <span class="text-xs uppercase text-slate-400">{{ $application->locale }}</span>
                            <span class="text-xs text-slate-400">{{ $application->created_at->format('d.m.Y H:i') }}</span>
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full {{ $statusClass($application->status) }}">{{ \App\Models\JobApplication::STATUSES[$application->status] ?? $application->status }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
<div class="mt-4">{{ $applications->links() }}</div>
@endsection
