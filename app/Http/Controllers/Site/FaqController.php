<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeEntry;
use App\Models\SeoSetting;

/** Public FAQ from the AI knowledge base (only the types chosen in SEO & AI visibility). */
class FaqController extends Controller
{
    public function __invoke(string $locale)
    {
        $settings = SeoSetting::current();
        abort_unless($settings->faq_enabled, 404);

        $entries = KnowledgeEntry::active()->whereIn('type', $settings->faqTypes())->get()
            ->map(fn (KnowledgeEntry $e) => [
                'type' => $e->type,
                'question' => $e->getTranslation('question', $locale, false),
                'answer' => $e->getTranslation('answer', $locale, false),
            ])
            ->filter(fn ($e) => filled($e['question']) && filled($e['answer']))
            ->groupBy('type');

        return view('site.faq', ['groups' => $entries, 'types' => KnowledgeEntry::TYPES]);
    }
}
