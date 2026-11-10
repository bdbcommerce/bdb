@extends('site.layout')

@php
    $locale = app()->getLocale();
    $why = __('site.careers.why');
    $why = is_array($why) ? $why : [];
    $departments = $vacancies->pluck('department')->unique()->values();
    $selected = (string) old('vacancy_id', request()->integer('vacancy') ?: '');
    if ($selected !== '' && ! $vacancies->contains('id', (int) $selected)) {
        $selected = '';
    }
    $address = $companyInfo->getTranslation('address', $locale, false);

    // JobPosting structured data: search engines (Google Jobs) and AI assistants read open positions from it.
    $schema = $vacancies->map(fn ($v) => array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'JobPosting',
        'title' => $v->titleIn($locale),
        'description' => nl2br(e(trim(implode("\n\n", array_filter([
            $v->getTranslation('description', $locale, false),
            implode("\n", $v->lines('requirements', $locale)),
            implode("\n", $v->lines('offer', $locale)),
        ]))) ?: $v->titleIn($locale))),
        'datePosted' => $v->created_at?->toDateString(),
        'employmentType' => \App\Models\Vacancy::SCHEMA_TYPES[$v->employment_type] ?? null,
        'hiringOrganization' => ['@type' => 'Organization', 'name' => 'BDB', 'sameAs' => url('/'), 'logo' => url('/images/logo.jpg')],
        'jobLocation' => ['@type' => 'Place', 'address' => array_filter([
            '@type' => 'PostalAddress',
            'addressLocality' => $v->getTranslation('location', $locale, false) ?: null,
            'streetAddress' => $address ?: null,
            'addressCountry' => 'UZ',
        ])],
        'directApply' => true,
    ]))->values()->all();

    $field = 'w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-600/30 focus:border-brand-600';
    $border = fn (string $name) => $errors->has($name) ? 'border-red-500' : 'border-gray-300';
@endphp

@section('title', __('site.careers.nav'))
@section('description', __('site.careers.lead'))

@push('schema')
    @if($schema)
        <script type="application/ld+json">{!! json_encode(count($schema) === 1 ? $schema[0] : $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endif
@endpush

@section('content')

@include('site.partials.page-header', [
    'title'   => __('site.careers.title'),
    'lead'    => __('site.careers.lead'),
    'eyebrow' => __('site.careers.eyebrow'),
    'crumbs'  => [['label' => __('site.careers.nav'), 'url' => null]],
])

{{-- Why BDB --}}
@if($why)
<section class="bg-gray-50 border-b border-gray-200" aria-labelledby="careers-why">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-14">
        <h2 id="careers-why" class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500">{{ __('site.careers.why_title') }}</h2>
        <ul class="mt-6 grid gap-4 md:grid-cols-3">
            @foreach($why as $item)
                <li class="flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-5">
                    <span class="icon-tile">@include('site.partials.icon', ['name' => $item['icon'] ?? 'check', 'class' => 'size-5'])</span>
                    <span>
                        <span class="block font-semibold text-gray-900">{{ $item['title'] }}</span>
                        <span class="block mt-1 text-sm text-gray-600 leading-relaxed">{{ $item['text'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@endif

<section class="bg-white"
         x-data="{
             dept: 'all',
             vacancy: @js($selected),
             apply(id) {
                 this.vacancy = String(id);
                 this.$nextTick(() => {
                     const form = document.getElementById('apply');
                     form.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
                     document.getElementById('cv-name')?.focus({ preventScroll: true });
                 });
             },
         }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14 md:py-20">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-10 lg:gap-12">

            {{-- Open positions --}}
            <div class="lg:col-span-3 min-w-0" aria-labelledby="careers-open">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="careers-open" class="text-2xl md:text-3xl font-semibold tracking-tight text-gray-900">{{ __('site.careers.open_title') }}</h2>
                    <p class="text-sm text-gray-500 tabular-nums">{{ $vacancies->isEmpty() ? __('site.careers.open_none') : trans_choice('site.careers.open_count', $vacancies->count()) }}</p>
                </div>

                @if($vacancies->isEmpty())
                    <div class="mt-8 rounded-2xl border border-dashed border-gray-300 p-8 text-center">
                        <span class="icon-tile mx-auto">@include('site.partials.icon', ['name' => 'users', 'class' => 'size-5'])</span>
                        <p class="mt-4 font-semibold text-gray-900">{{ __('site.careers.none_title') }}</p>
                        <p class="mt-2 text-sm text-gray-600 max-w-md mx-auto leading-relaxed">{{ __('site.careers.none_text') }}</p>
                    </div>
                @else
                    @if($departments->count() > 1)
                        <div class="mt-6 -mx-4 px-4 overflow-x-auto scrollbar-none" role="group" aria-label="{{ __('site.careers.filter_label') }}">
                            <div class="flex gap-2 w-max">
                                <button type="button" @click="dept = 'all'" :aria-pressed="(dept === 'all').toString()" aria-pressed="true"
                                        :class="dept === 'all' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'"
                                        class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors border-gray-900 bg-gray-900 text-white">
                                    {{ __('site.careers.all') }}
                                </button>
                                @foreach($departments as $dep)
                                    <button type="button" @click="dept = @js($dep)" :aria-pressed="(dept === @js($dep)).toString()" aria-pressed="false"
                                            :class="dept === @js($dep) ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-400'"
                                            class="rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors border-gray-200 bg-white text-gray-600">
                                        {{ __('site.careers.departments.'.$dep) }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <ul class="mt-6 space-y-4">
                        @foreach($vacancies as $vacancy)
                            @php
                                $description = $vacancy->getTranslation('description', $locale, false);
                                $requirements = $vacancy->lines('requirements', $locale);
                                $offer = $vacancy->lines('offer', $locale);
                                $location = $vacancy->getTranslation('location', $locale, false);
                            @endphp
                            <li id="vacancy-{{ $vacancy->id }}" x-show="dept === 'all' || dept === @js($vacancy->department)"
                                class="scroll-mt-24 rounded-2xl border border-gray-200 bg-white hover:border-gray-300 transition-colors">
                                <details class="group" @if($loop->first && $vacancies->count() <= 3) open @endif>
                                    <summary class="flex cursor-pointer list-none items-start gap-4 p-5 sm:p-6 [&::-webkit-details-marker]:hidden rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('site.careers.departments.'.$vacancy->department) }}</span>
                                            <h3 class="mt-1 text-lg font-semibold text-gray-900 break-words">{{ $vacancy->titleIn($locale) }}</h3>
                                            <span class="mt-3 flex flex-wrap gap-2">
                                                <span class="chip">@include('site.partials.icon', ['name' => 'clock', 'class' => 'size-3.5'])
                                                    {{ __('site.careers.types.'.$vacancy->employment_type) }}</span>
                                                @if($location)
                                                    <span class="chip">@include('site.partials.icon', ['name' => 'map-pin', 'class' => 'size-3.5']) {{ $location }}</span>
                                                @endif
                                                @if($vacancy->salary)
                                                    <span class="chip">@include('site.partials.icon', ['name' => 'banknotes', 'class' => 'size-3.5'])
                                                        <span class="sr-only">{{ __('site.careers.salary') }}:</span> {{ $vacancy->salary }}</span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="mt-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-transform group-open:rotate-180" aria-hidden="true">
                                            @include('site.partials.icon', ['name' => 'chevron-down', 'class' => 'size-4'])
                                        </span>
                                    </summary>
                                    <div class="border-t border-gray-100 px-5 pb-6 pt-5 sm:px-6 text-sm text-gray-700 leading-relaxed space-y-5">
                                        @if($description)
                                            <p class="break-words">{!! nl2br(e($description)) !!}</p>
                                        @endif
                                        @foreach(['requirements' => $requirements, 'offer' => $offer] as $key => $items)
                                            @if($items)
                                                <div>
                                                    <h4 class="font-semibold text-gray-900">{{ __('site.careers.'.$key) }}</h4>
                                                    <ul class="mt-2 space-y-1.5">
                                                        @foreach($items as $line)
                                                            <li class="flex gap-2.5 break-words">
                                                                @include('site.partials.icon', ['name' => 'check', 'class' => 'size-4 mt-0.5 shrink-0 text-gray-400'])
                                                                <span class="min-w-0">{{ $line }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        @endforeach
                                        <a href="{{ route('site.careers', [$locale, 'vacancy' => $vacancy->id]) }}#apply"
                                           @click.prevent="apply({{ $vacancy->id }})" class="btn-primary !py-2.5">
                                            {{ __('site.careers.apply_btn') }}
                                            @include('site.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])
                                        </a>
                                    </div>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Application form --}}
            <div class="lg:col-span-2">
                <div id="apply" class="scroll-mt-24 lg:sticky lg:top-24 rounded-2xl border border-gray-200 bg-gray-50 p-5 sm:p-7">
                    <h2 class="text-xl font-semibold tracking-tight text-gray-900">{{ __('site.careers.form.title') }}</h2>
                    <p class="mt-2 text-sm text-gray-600 leading-relaxed">{{ __('site.careers.form.lead') }}</p>

                    @if(session('careers_sent'))
                        <div class="mt-5 flex gap-3 rounded-xl border border-green-200 bg-white p-4" role="status">
                            @include('site.partials.icon', ['name' => 'check-badge', 'class' => 'size-6 shrink-0 text-green-700'])
                            <div>
                                <p class="font-semibold text-gray-900">{{ __('site.careers.form.sent_title') }}</p>
                                <p class="mt-0.5 text-sm text-gray-600">{{ __('site.careers.form.sent_text') }}</p>
                            </div>
                        </div>
                    @endif

                    @if($errors->any())
                        <p class="mt-5 rounded-lg border border-red-200 bg-white px-4 py-3 text-sm text-red-700" role="alert">{{ __('site.careers.form.errors') }}</p>
                    @endif

                    <form method="POST" action="{{ route('site.careers.apply', $locale) }}" enctype="multipart/form-data"
                          class="mt-6 space-y-4" x-data="{ sending: false }" @submit="sending = true">
                        @csrf
                        {{-- Honeypot for bots --}}
                        <div class="hidden" aria-hidden="true">
                            <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>

                        <div>
                            <label for="cv-position" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.position') }}</label>
                            <select id="cv-position" name="vacancy_id" x-model="vacancy" @error('vacancy_id') aria-invalid="true" aria-describedby="cv-position-error" @enderror class="{{ $field }} {{ $border('vacancy_id') }}">
                                <option value="">{{ __('site.careers.form.general') }}</option>
                                @foreach($vacancies as $vacancy)
                                    <option value="{{ $vacancy->id }}" @selected($selected === (string) $vacancy->id)>{{ $vacancy->titleIn($locale) }}</option>
                                @endforeach
                            </select>
                            @error('vacancy_id')<p id="cv-position-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <div x-show="vacancy === ''" @if($selected !== '') x-cloak @endif>
                            <label for="cv-department" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.department') }}</label>
                            <select id="cv-department" name="department" :disabled="vacancy !== ''" class="{{ $field }} {{ $border('department') }}">
                                <option value="">{{ __('site.careers.form.any_department') }}</option>
                                @foreach(\App\Models\Vacancy::DEPARTMENTS as $dep)
                                    <option value="{{ $dep }}" @selected(old('department') === $dep)>{{ __('site.careers.departments.'.$dep) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="cv-name" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.name') }} <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input id="cv-name" type="text" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"
                                   @error('name') aria-invalid="true" aria-describedby="cv-name-error" @enderror
                                   class="{{ $field }} {{ $border('name') }}">
                            @error('name')<p id="cv-name-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            <div>
                                <label for="cv-phone" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.phone') }} <span class="text-red-600" aria-hidden="true">*</span></label>
                                <input id="cv-phone" type="tel" name="phone" value="{{ old('phone') }}" required maxlength="40" autocomplete="tel" placeholder="+998 __ ___ __ __"
                                       @error('phone') aria-invalid="true" aria-describedby="cv-phone-error" @enderror
                                       class="{{ $field }} {{ $border('phone') }}">
                                @error('phone')<p id="cv-phone-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="cv-email" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.email') }} <span class="font-normal text-gray-500">({{ __('site.careers.form.optional') }})</span></label>
                                <input id="cv-email" type="email" name="email" value="{{ old('email') }}" maxlength="190" autocomplete="email"
                                       @error('email') aria-invalid="true" aria-describedby="cv-email-error" @enderror
                                       class="{{ $field }} {{ $border('email') }}">
                                @error('email')<p id="cv-email-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div>
                            <label for="cv-message" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.message') }} <span class="font-normal text-gray-500">({{ __('site.careers.form.optional') }})</span></label>
                            <textarea id="cv-message" name="message" rows="4" maxlength="2000" aria-describedby="cv-message-hint{{ $errors->has('message') ? ' cv-message-error' : '' }}" @error('message') aria-invalid="true" @enderror
                                      class="{{ $field }} {{ $border('message') }}">{{ old('message') }}</textarea>
                            <p id="cv-message-hint" class="mt-1 text-xs text-gray-500">{{ __('site.careers.form.message_hint') }}</p>
                            @error('message')<p id="cv-message-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="cv-file" class="block text-sm font-medium text-gray-800 mb-1.5">{{ __('site.careers.form.cv') }} <span class="text-red-600" aria-hidden="true">*</span></label>
                            <input id="cv-file" type="file" name="cv" required
                                   accept=".pdf,.doc,.docx,.rtf,.odt,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                   aria-describedby="cv-file-hint{{ $errors->has('cv') ? ' cv-file-error' : '' }}" @error('cv') aria-invalid="true" @enderror
                                   class="block w-full rounded-lg border {{ $border('cv') }} bg-white text-sm text-gray-700 file:mr-3 file:border-0 file:border-r file:border-gray-200 file:bg-gray-100 file:px-4 file:py-2.5 file:text-sm file:font-medium file:text-gray-800 hover:file:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
                            <p id="cv-file-hint" class="mt-1 text-xs text-gray-500">{{ __('site.careers.form.cv_hint') }}</p>
                            @error('cv')<p id="cv-file-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label class="flex items-start gap-3 text-sm text-gray-700">
                                <input type="checkbox" name="consent" value="1" required @checked(old('consent')) @error('consent') aria-invalid="true" aria-describedby="cv-consent-error" @enderror
                                       class="mt-0.5 size-4 shrink-0 rounded border-gray-300 text-gray-900 focus:ring-brand-600/30">
                                <span>{{ __('site.careers.form.consent') }}</span>
                            </label>
                            @error('consent')<p id="cv-consent-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
                        </div>

                        <button type="submit" class="btn-primary w-full" :disabled="sending" :class="sending && 'opacity-70 cursor-wait'">
                            <span x-show="! sending">{{ __('site.careers.form.submit') }}</span>
                            <span x-show="sending" x-cloak>{{ __('site.careers.form.sending') }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection
