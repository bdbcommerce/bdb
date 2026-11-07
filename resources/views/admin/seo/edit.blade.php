@extends('admin.layout')
@section('title', 'SEO & AI visibility')
@section('heading', 'SEO & AI visibility')

@php
    $card = 'bg-white border border-slate-200 rounded-xl p-6 space-y-4';
    $h2 = 'text-xs font-semibold text-slate-500 uppercase tracking-wide';
    $input = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-400';
    $secondaryBtn = 'inline-flex items-center justify-center gap-2 border border-slate-300 bg-white text-slate-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors';
    $link = 'font-medium text-slate-700 underline hover:text-slate-900';
    $byCompany = collect($crawlers)->groupBy(fn ($c) => $c[0], true);
@endphp

@section('content')
<div class="w-full space-y-6">
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
            <ul class="list-disc pl-5 space-y-0.5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @unless($publicHttps)
        <p role="alert" class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            This site runs at {{ url('/') }}. On the server set APP_URL to the public https:// address so robots.txt, the sitemap and llms.txt show the real links.
        </p>
    @endunless

    {{-- Live files --}}
    <div class="{{ $card }}">
        <div>
            <h2 class="{{ $h2 }}">Files for search engines and AI</h2>
            <p class="text-xs text-slate-400 mt-1">Generated from the site content and the settings below; always current.</p>
        </div>
        <ul class="grid gap-3 sm:grid-cols-3 text-sm">
            <li class="rounded-lg border border-slate-200 p-3">
                <a href="{{ url('/robots.txt') }}" target="_blank" rel="noopener" class="{{ $link }}">robots.txt</a>
                <p class="text-xs text-slate-500 mt-1">Which crawlers may visit</p>
            </li>
            <li class="rounded-lg border border-slate-200 p-3">
                <a href="{{ url('/sitemap.xml') }}" target="_blank" rel="noopener" class="{{ $link }}">sitemap.xml</a>
                <p class="text-xs text-slate-500 mt-1">{{ $urlCount }} pages in 3 languages</p>
            </li>
            <li class="rounded-lg border border-slate-200 p-3">
                @if($settings->llms_enabled)
                    <a href="{{ url('/llms.txt') }}" target="_blank" rel="noopener" class="{{ $link }}">llms.txt</a>
                @else
                    <span class="font-medium text-slate-400">llms.txt (off)</span>
                @endif
                <p class="text-xs text-slate-500 mt-1">Company summary for AI assistants</p>
            </li>
        </ul>
        <form method="POST" action="{{ route('admin.seo.refresh') }}">
            @csrf
            <button class="{{ $secondaryBtn }}">Rebuild sitemap and llms.txt now</button>
        </form>
    </div>

    <form method="POST" action="{{ route('admin.seo.update') }}" class="space-y-6">
        @csrf @method('PUT')

        {{-- Crawlers --}}
        <div class="{{ $card }}" x-data>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="{{ $h2 }}">AI and search crawlers</h2>
                    <p class="text-xs text-slate-400 mt-1">Ticked = allowed in robots.txt. The admin area, chat and Telegram endpoints are always closed.</p>
                </div>
                <div class="flex gap-2 text-xs">
                    <button type="button" class="{{ $link }}" @click="$root.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = true)">Allow all</button>
                </div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($byCompany as $company => $bots)
                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-1 text-sm font-semibold text-slate-800">{{ $company }}</legend>
                        <div class="space-y-2">
                            @foreach($bots as $bot => [$c, $purpose])
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="hidden" name="crawlers[{{ $bot }}]" value="0">
                                    <input type="checkbox" name="crawlers[{{ $bot }}]" value="1" @checked(old('crawlers.'.$bot, $settings->allows($bot)))
                                           class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                                    <span>
                                        <code class="font-mono text-slate-900">{{ $bot }}</code>
                                        <span class="block text-xs text-slate-500">{{ $purpose }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <div>
                <label for="robots_extra" class="block text-sm font-medium text-slate-700 mb-1">Extra robots.txt rules (optional)</label>
                <textarea id="robots_extra" name="robots_extra" rows="3" maxlength="2000" class="{{ $input }} font-mono" placeholder="Disallow: /uz/news/drafts">{{ old('robots_extra', $settings->robots_extra) }}</textarea>
            </div>
            <details class="text-xs">
                <summary class="cursor-pointer text-slate-600">Show the current robots.txt</summary>
                <pre class="mt-2 max-h-72 overflow-auto rounded-lg bg-slate-50 border border-slate-200 p-3 text-[11px] leading-relaxed">{{ $robots }}</pre>
            </details>
        </div>

        {{-- llms.txt + FAQ --}}
        <div class="{{ $card }}">
            <h2 class="{{ $h2 }}">What AI assistants read about the company</h2>
            <label class="flex items-start gap-2 text-sm">
                <input type="hidden" name="llms_enabled" value="0">
                <input type="checkbox" name="llms_enabled" value="1" @checked(old('llms_enabled', $settings->llms_enabled)) class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                <span>Publish <code>llms.txt</code>
                    <span class="block text-xs text-slate-500">Company facts, pages, brands, industries and articles — built automatically.</span></span>
            </label>
            <div>
                <label for="llms_summary" class="block text-sm font-medium text-slate-700 mb-1">Company summary (English, optional)</label>
                <textarea id="llms_summary" name="llms_summary" rows="3" maxlength="1500" class="{{ $input }}"
                          placeholder="Empty = the “About” text from Profile & SEO">{{ old('llms_summary', $settings->llms_summary) }}</textarea>
                <p class="text-xs text-slate-400 mt-1">One or two sentences: who BDB is, what you sell, where. AI assistants often quote this.</p>
            </div>

            <div class="border-t border-slate-100 pt-4 space-y-3">
                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="faq_enabled" value="0">
                    <input type="checkbox" name="faq_enabled" value="1" @checked(old('faq_enabled', $settings->faq_enabled)) class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                    <span>Public FAQ page
                        <span class="block text-xs text-slate-500">
                            Questions and answers from the <a href="{{ route('admin.knowledge.index') }}" class="{{ $link }}">knowledge base</a>, in 3 languages, linked in the footer.
                            {{ $faqCount }} active {{ Str::plural('entry', $faqCount) }} of the chosen types.
                            @if($settings->faq_enabled)
                                <a href="{{ route('site.faq', 'ru') }}" target="_blank" rel="noopener" class="{{ $link }}">Open</a>
                            @endif
                        </span></span>
                </label>
                <fieldset>
                    <legend class="text-xs font-medium text-slate-600 mb-1">Knowledge types shown on the FAQ</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach($types as $key => $label)
                            <label class="inline-flex items-center gap-1.5 text-sm">
                                <input type="checkbox" name="faq_types[]" value="{{ $key }}" @checked(in_array($key, old('faq_types', $settings->faqTypes()), true))
                                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-400 mt-1">Leave internal topics (e.g. pricing notes) unticked.</p>
                </fieldset>
            </div>

            <div class="border-t border-slate-100 pt-4">
                <label for="opening_hours" class="block text-sm font-medium text-slate-700 mb-1">Opening hours</label>
                <input id="opening_hours" name="opening_hours" maxlength="190" value="{{ old('opening_hours', $settings->opening_hours) }}"
                       placeholder="Mo-Fr 09:00-18:00; Sa 10:00-14:00" class="{{ $input }} max-w-md">
                <p class="text-xs text-slate-400 mt-1">Shown to search engines with the address and the map location (from Profile &amp; SEO).</p>
            </div>
        </div>

        {{-- Webmaster tools --}}
        <div class="{{ $card }}">
            <div>
                <h2 class="{{ $h2 }}">Webmaster accounts</h2>
                <p class="text-xs text-slate-400 mt-1">Paste only the code from the “HTML tag” verification method (the value of <code>content="…"</code>).</p>
            </div>
            @foreach([
                'google_verification' => ['Google Search Console', 'https://search.google.com/search-console', 'Google Search and AI Overviews'],
                'bing_verification' => ['Bing Webmaster Tools', 'https://www.bing.com/webmasters', 'Bing, Copilot and ChatGPT search'],
                'yandex_verification' => ['Yandex Webmaster', 'https://webmaster.yandex.com', 'Yandex and Alice'],
            ] as $field => [$name, $url, $for])
                <div>
                    <label for="{{ $field }}" class="block text-sm font-medium text-slate-700 mb-1">
                        {{ $name }} <span class="font-normal text-slate-400">· {{ $for }} ·</span>
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-xs {{ $link }}">open</a>
                    </label>
                    <input id="{{ $field }}" name="{{ $field }}" maxlength="120" value="{{ old($field, $settings->$field) }}" class="{{ $input }} max-w-md font-mono">
                </div>
            @endforeach
            <p class="text-xs text-slate-500">After verifying, add <code>{{ url('/sitemap.xml') }}</code> as the sitemap in each account.</p>
        </div>

        {{-- IndexNow --}}
        <div class="{{ $card }}">
            <h2 class="{{ $h2 }}">IndexNow — instant indexing</h2>
            <label class="flex items-start gap-2 text-sm">
                <input type="hidden" name="indexnow_enabled" value="0">
                <input type="checkbox" name="indexnow_enabled" value="1" @checked(old('indexnow_enabled', $settings->indexnow_enabled)) class="mt-0.5 rounded border-slate-300 text-slate-900 focus:ring-slate-400">
                <span>Tell Bing and Yandex about new and changed articles right away
                    <span class="block text-xs text-slate-500">Pages then appear in Copilot and Alice within days instead of weeks. Key file: <code>{{ url('/'.$settings->indexNowKey().'.txt') }}</code></span></span>
            </label>
            @if($settings->indexnow_last_at)
                <p class="text-xs text-slate-500">Last send: {{ $settings->indexnow_last_at->format('d.m.Y H:i') }} — {{ $settings->indexnow_last_result }}</p>
            @endif
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-slate-900 text-white text-sm font-medium rounded-lg px-6 py-2.5 hover:bg-slate-700 transition-colors">Save settings</button>
        </div>
    </form>

    @if($settings->indexnow_enabled)
        <form method="POST" action="{{ route('admin.seo.indexnow') }}" class="{{ $card }}">
            @csrf
            <p class="text-sm text-slate-700">Send all {{ $urlCount }} pages to IndexNow now (useful once after launch).</p>
            <button class="{{ $secondaryBtn }}">Send all pages</button>
        </form>
    @endif

    {{-- Checklist outside the site --}}
    <div class="{{ $card }}">
        <h2 class="{{ $h2 }}">Outside the website (done by the team)</h2>
        <ul class="list-disc pl-5 space-y-1 text-sm text-slate-700">
            <li>Company cards with the same name, address and phone: <strong>Yandex Business</strong>, <strong>Google Business Profile</strong>, <strong>2GIS</strong>.</li>
            <li>“Where to buy / partners” listings on the sites of the brands you represent, and a LinkedIn company page.</li>
            <li>Mentions in industry news and Asl Belgi marking resources, linking to the site.</li>
            <li>Once a month, ask ChatGPT, Perplexity, Copilot and Alice e.g. “where to buy a label printer in Tashkent” and note the answers.</li>
        </ul>
    </div>
</div>
@endsection
