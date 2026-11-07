@extends('site.layout')

@section('title', __('site.faq.title'))
@section('description', __('site.faq.lead'))

@php
    $locale = app()->getLocale();
    // FAQPage structured data: AI assistants and search engines read these questions and answers directly.
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $groups->flatten(1)->map(fn ($e) => [
            '@type' => 'Question',
            'name' => strip_tags($e['question']),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(strip_tags($e['answer']))],
        ])->values()->all(),
    ];
@endphp

@push('schema')
    @if($groups->isNotEmpty())
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endif
@endpush

@section('content')
@include('site.partials.page-header', ['title' => __('site.faq.title'), 'lead' => __('site.faq.lead')])

<section class="py-12 md:py-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        @forelse($groups as $type => $entries)
            <div class="mb-10 last:mb-0">
                @if($groups->count() > 1)
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 mb-3">{{ $types[$type] ?? $type }}</h2>
                @endif
                <div class="divide-y divide-gray-200 border-y border-gray-200">
                    @foreach($entries as $entry)
                        <details class="group py-4">
                            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 text-base font-medium text-gray-900 [&::-webkit-details-marker]:hidden">
                                <span>{{ $entry['question'] }}</span>
                                @include('site.partials.icon', ['name' => 'chevron-down', 'class' => 'size-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180'])
                            </summary>
                            <div class="mt-3 text-gray-600 leading-relaxed whitespace-pre-line break-words">{{ $entry['answer'] }}</div>
                        </details>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-center text-gray-500">{{ __('site.faq.empty') }}</p>
        @endforelse

        <div class="mt-12 rounded-2xl border border-gray-200 bg-gray-50 px-6 py-6 text-center">
            <p class="text-gray-700">{{ __('site.faq.more') }}</p>
            <a href="{{ route('site.contact', $locale) }}" class="btn-primary mt-4 inline-flex">{{ __('site.cta.contact') }}</a>
        </div>
    </div>
</section>
@endsection
