<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeEntry;
use App\Models\SeoSetting;
use App\Services\Seo\IndexNow;
use App\Services\Seo\SeoFiles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin → Company → SEO & AI visibility. */
class SeoSettingController extends Controller
{
    public function edit(SeoFiles $files)
    {
        $settings = SeoSetting::current();

        return view('admin.seo.edit', [
            'settings' => $settings,
            'crawlers' => SeoSetting::CRAWLERS,
            'types' => KnowledgeEntry::TYPES,
            'faqCount' => KnowledgeEntry::active()->whereIn('type', $settings->faqTypes())->count(),
            'robots' => $files->robots(),
            'urlCount' => count($files->urls()),
            'publicHttps' => str_starts_with(url('/'), 'https://'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'crawlers' => ['array'],
            'crawlers.*' => ['boolean'],
            'robots_extra' => ['nullable', 'string', 'max:2000', function ($attribute, $value, $fail) {
                foreach (preg_split('/\R/', (string) $value) as $line) {
                    $line = trim($line);
                    if ($line !== '' && ! str_starts_with($line, '#')
                        && ! preg_match('/^(User-agent|Allow|Disallow|Crawl-delay|Sitemap|Clean-param|Host)\s*:\s*\S.*$/i', $line)) {
                        $fail("Not a robots.txt rule: “{$line}”. Use lines like “Disallow: /path” or “User-agent: Name”.");

                        return;
                    }
                }
            }],
            'llms_enabled' => ['boolean'],
            'llms_summary' => ['nullable', 'string', 'max:1500'],
            'faq_enabled' => ['boolean'],
            'faq_types' => ['array'],
            'faq_types.*' => [Rule::in(array_keys(KnowledgeEntry::TYPES))],
            'opening_hours' => ['nullable', 'string', 'max:190', 'regex:/^((Mo|Tu|We|Th|Fr|Sa|Su)(-(Mo|Tu|We|Th|Fr|Sa|Su))?(,(Mo|Tu|We|Th|Fr|Sa|Su)(-(Mo|Tu|We|Th|Fr|Sa|Su))?)* \d{2}:\d{2}-\d{2}:\d{2})(;\s*(Mo|Tu|We|Th|Fr|Sa|Su)(-(Mo|Tu|We|Th|Fr|Sa|Su))?(,(Mo|Tu|We|Th|Fr|Sa|Su)(-(Mo|Tu|We|Th|Fr|Sa|Su))?)* \d{2}:\d{2}-\d{2}:\d{2})*$/'],
            'google_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'bing_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'yandex_verification' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'indexnow_enabled' => ['boolean'],
        ], [
            'opening_hours.regex' => 'Write the hours like “Mo-Fr 09:00-18:00; Sa 10:00-14:00”.',
            '*.regex' => 'Paste only the code from the verification meta tag (letters, digits, - and _).',
        ]);

        $settingsNow = SeoSetting::current();
        $crawlers = [];
        foreach (array_keys(SeoSetting::CRAWLERS) as $bot) {
            // A field missing from the request keeps its current value (the form always sends all).
            $crawlers[$bot] = array_key_exists($bot, $data['crawlers'] ?? []) ? (bool) $data['crawlers'][$bot] : $settingsNow->allows($bot);
        }

        $settings = SeoSetting::current();
        $settings->fill([
            'crawlers' => $crawlers,
            'faq_types' => $request->has('faq_types') || $request->has('faq_enabled') ? ($data['faq_types'] ?? []) : $settingsNow->faqTypes(),
            'llms_enabled' => $request->boolean('llms_enabled'),
            'faq_enabled' => $request->boolean('faq_enabled'),
            'indexnow_enabled' => $request->boolean('indexnow_enabled'),
        ] + collect($data)->only(['robots_extra', 'llms_summary', 'opening_hours', 'google_verification', 'bing_verification', 'yandex_verification'])->all());
        $settings->indexNowKey();
        $settings->save();

        return redirect()->route('admin.seo.edit')->with('success', 'SEO & AI visibility settings saved.');
    }

    /** Send every page to IndexNow (Bing, Yandex…) now. */
    public function submitAll(SeoFiles $files, IndexNow $indexNow)
    {
        if (! SeoSetting::current()->indexnow_enabled) {
            return back()->withErrors(['indexnow' => 'Turn on IndexNow and save first.']);
        }

        return back()->with('success', 'IndexNow: '.($indexNow->submit($files->urls()) ?? 'nothing to send.'));
    }

    /** Rebuild sitemap.xml and llms.txt now (they otherwise refresh within an hour or on save). */
    public function refresh()
    {
        SeoSetting::flush();

        return back()->with('success', 'sitemap.xml and llms.txt are rebuilt from the current content.');
    }
}
