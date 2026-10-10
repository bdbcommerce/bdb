<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Belgi" — the website chat assistant. Answers only from CMS content and the
 * admin-managed knowledge base, using Google Gemini. Returns structured replies:
 * text, quick-reply chips and a flag that opens the callback form.
 */
class ChatAssistant
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /** Hard cap for brief (Telegram) replies, in characters. */
    public const BRIEF_MAX = 300;

    private const LANGUAGES = ['uz' => 'Uzbek (Latin script)', 'ru' => 'Russian', 'en' => 'English'];

    private const REPLY_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'reply' => ['type' => 'STRING'],
            'suggestions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'offer_callback' => ['type' => 'BOOLEAN'],
            'answered' => ['type' => 'BOOLEAN'],
        ],
        'required' => ['reply', 'suggestions', 'offer_callback', 'answered'],
        'propertyOrdering' => ['reply', 'suggestions', 'offer_callback', 'answered'],
    ];

    /**
     * @param  array<int, array{role: string, content: string}>  $history  roles: user|assistant
     * @param  bool  $brief  one or two short sentences (Telegram)
     * @return array{reply: string, suggestions: array<int, string>, offer_callback: bool, answered: bool}
     */
    public function reply(array $history, string $locale, ?string $pageTitle = null, bool $brief = false): array
    {
        $model = config('services.gemini.model');

        $generationConfig = [
            'temperature' => 0.2,
            'maxOutputTokens' => 1024,
            'responseMimeType' => 'application/json',
            'responseSchema' => self::REPLY_SCHEMA,
        ];
        if (str_contains($model, 'flash')) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        try {
            $response = Http::timeout(25)
                ->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->post(sprintf(self::ENDPOINT, $model), [
                    'system_instruction' => ['parts' => [['text' => $this->systemPrompt($locale, $pageTitle, $brief)]]],
                    'contents' => array_map(fn (array $m) => [
                        'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $m['content']]],
                    ], $history),
                    'generationConfig' => $generationConfig,
                ]);
        } catch (ConnectionException $e) {
            // Timeout or network failure: answer with the callback offer instead of a 500.
            Log::warning('Gemini chat request failed', ['error' => $e->getMessage()]);

            return $this->fallbackReply($locale);
        }

        if ($response->failed()) {
            Log::warning('Gemini chat request failed', ['status' => $response->status(), 'body' => $response->body()]);

            return $this->fallbackReply($locale);
        }

        $data = json_decode((string) $response->json('candidates.0.content.parts.0.text'), true);

        if (! is_array($data) || empty($data['reply'])) {
            return $this->fallbackReply($locale);
        }

        return [
            'reply' => $brief ? self::shorten((string) $data['reply'], self::BRIEF_MAX) : (string) $data['reply'],
            'suggestions' => array_slice(array_map('strval', $data['suggestions'] ?? []), 0, 3),
            'offer_callback' => (bool) ($data['offer_callback'] ?? false),
            'answered' => (bool) ($data['answered'] ?? true),
        ];
    }

    /** @return array{reply: string, suggestions: array<int, string>, offer_callback: bool, answered: bool} */
    public function fallbackReply(string $locale): array
    {
        return [
            'reply' => __('chat.error', [], $locale),
            'suggestions' => [],
            'offer_callback' => true,
            'answered' => false,
        ];
    }

    public static function forgetKnowledge(): void
    {
        foreach (array_keys(self::LANGUAGES) as $locale) {
            Cache::forget("chat.knowledge.{$locale}");
        }
    }

    /** Cut text to $max characters, at the end of a sentence when possible. */
    public static function shorten(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        if (preg_match('/^.*[.!?](?=\s|$)/su', $cut, $m) && mb_strlen($m[0]) >= $max / 3) {
            return $m[0];
        }

        return rtrim(preg_replace('/\s+\S*$/u', '', $cut), ' ,;:-').'…';
    }

    private function systemPrompt(string $locale, ?string $pageTitle, bool $brief = false): string
    {
        $language = self::LANGUAGES[$locale] ?? 'English';
        $length = $brief
            ? 'At most 2 short sentences and under '.self::BRIEF_MAX.' characters in total. Plain text, no markdown, no lists.'
            : '1-4 short sentences, plain text, no markdown.';
        $page = $pageTitle ? "The visitor is currently on the page \"{$pageTitle}\"." : '';

        return <<<PROMPT
        You are Belgi, the AI assistant on the website of BDB, an automatic identification (AIDC) company in Uzbekistan.

        STRICT RULE: answer only with information found in the WEBSITE DATA below. It is your only source of truth.
        - If the answer is not in the website data, say you don't have that information and offer to connect the visitor with the BDB team (set offer_callback to true). Do not guess and do not use general knowledge to fill gaps.
        - Never invent prices, stock, delivery times, model numbers, specifications, certifications, customers, or dates.
        - Questions unrelated to BDB and its products and services: politely decline in one sentence and steer back.
        - Knowledge base answers written by BDB staff take priority over other sections.

        Style:
        - Reply in {$language} unless the visitor writes in another language; then match theirs.
        - {$length} You may include a page URL from the data when it helps.
        - When helping someone choose equipment, ask one clarifying question at a time.
        - suggestions: 0-3 short follow-up questions the visitor might tap next (max 6 words each), answerable from the website data.
        - offer_callback: true when the visitor wants a quote, prices, a demo, to buy, or to talk to a person.
        - answered: false when the visitor asked about BDB, its products or services and the website data did not contain the answer; true otherwise (including greetings and off-topic questions you declined).

        {$page}

        WEBSITE DATA
        {$this->knowledge($locale)}
        PROMPT;
    }

    private function knowledge(string $locale): string
    {
        return Cache::remember("chat.knowledge.{$locale}", now()->addMinutes(10), function () use ($locale) {
            $t = fn ($model, string $field) => trim(preg_replace('/\s+/', ' ', strip_tags(
                (string) $model->getTranslation($field, $locale, true)
            )));
            $base = url('/'.$locale);
            $lines = [];

            $info = CompanyInfo::instance();
            $lines[] = '## Company';
            $lines[] = 'About: '.$t($info, 'about');
            $lines[] = 'Slogan: '.$t($info, 'slogan');
            $lines[] = 'Address: '.$t($info, 'address');
            foreach (['phone', 'email', 'telegram', 'instagram', 'facebook', 'linkedin', 'youtube'] as $field) {
                if ($info->{$field}) {
                    $lines[] = ucfirst($field).': '.$info->{$field};
                }
            }

            $entries = KnowledgeEntry::active()->get()->groupBy('type');
            if ($entries->isNotEmpty()) {
                $lines[] = "\n## Knowledge base (written by BDB staff)";
                foreach ($entries as $type => $items) {
                    $lines[] = '### '.(KnowledgeEntry::TYPES[$type] ?? $type);
                    foreach ($items as $entry) {
                        $lines[] = 'Q: '.$t($entry, 'question');
                        $lines[] = 'A: '.$t($entry, 'answer');
                        if ($entry->keywords) {
                            $lines[] = 'Keywords: '.$entry->keywords;
                        }
                        if ($entry->link) {
                            $lines[] = 'Link: '.$entry->link;
                        }
                    }
                }
            }

            $lines[] = "\n## Brands BDB distributes ({$base}/brands)";
            foreach (Brand::active()->get() as $brand) {
                $lines[] = '- '.$t($brand, 'name').': '.mb_substr($t($brand, 'description'), 0, 400);
            }

            $lines[] = "\n## Industries served ({$base}/industries)";
            foreach (Industry::active()->get() as $industry) {
                $lines[] = '- '.$t($industry, 'name').': '.mb_substr($t($industry, 'description'), 0, 400);
            }

            $customers = Customer::active()->pluck('name')->filter()->implode(', ');
            if ($customers) {
                $lines[] = "\n## Customers ({$base}/customers)\n".$customers;
            }

            $articles = Article::published()->limit(8)->get();
            if ($articles->isNotEmpty()) {
                $lines[] = "\n## Recent news ({$base}/news)";
                foreach ($articles as $article) {
                    $lines[] = '- '.$t($article, 'title').': '.mb_substr($t($article, 'excerpt'), 0, 250);
                }
            }

            $lines[] = "\n## Site pages\nAbout {$base}/about, Brands {$base}/brands, Industries {$base}/industries, Customers {$base}/customers, News {$base}/news, Contact {$base}/contact";

            return implode("\n", $lines);
        });
    }
}
