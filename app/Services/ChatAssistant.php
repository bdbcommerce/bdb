<?php

namespace App\Services;

use App\Models\AiInstruction;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\CompanyInfo;
use App\Models\Customer;
use App\Models\Industry;
use App\Models\KnowledgeEntry;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Cache;

/**
 * "Belgi" — the website chat assistant. Answers only from CMS content and the
 * admin-managed knowledge base, using Google Gemini. Returns structured replies:
 * text, quick-reply chips and a flag that opens the callback form.
 */
class ChatAssistant
{
    private ?string $lastError = null;

    private ?AiModel $lastModel = null;

    /**
     * The main instructions used until an admin edits them (stored as the "Main prompt" AI document).
     * Language, reply length and the JSON fields are added in code (see systemPrompt()).
     */
    public const DEFAULT_PROMPT = <<<'PROMPT'
    You are Belgi, the AI assistant of BDB, an automatic identification (AIDC) company in Uzbekistan. You answer customers on the BDB website and in the BDB Telegram bot.

    STRICT RULE: answer only with information found in the WEBSITE DATA below. It is your only source of truth.
    - If the answer is not in the website data, say you don't have that information and offer to connect the visitor with the BDB team (set offer_callback to true). Do not guess and do not use general knowledge to fill gaps.
    - Never invent prices, stock, delivery times, model numbers, specifications, certifications, customers, or dates.
    - Questions unrelated to BDB and its products and services: politely decline in one sentence and steer back.
    - Knowledge base answers written by BDB staff take priority over other sections.

    Style:
    - Be friendly, clear and professional. Plain words, no jargon unless the visitor uses it.
    - You may include a page URL from the data when it helps.
    - When helping someone choose equipment, ask one clarifying question at a time.
    PROMPT;

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
            // For the admin "Client requests" inbox:
            'knowledge_id' => ['type' => 'INTEGER'],
            'topic' => ['type' => 'STRING'],
            'category' => ['type' => 'STRING'],
        ],
        'required' => ['reply', 'suggestions', 'offer_callback', 'answered', 'knowledge_id', 'topic', 'category'],
        'propertyOrdering' => ['reply', 'suggestions', 'offer_callback', 'answered', 'knowledge_id', 'topic', 'category'],
    ];

    /** Categories the AI tags a question with: knowledge types plus non-request kinds. */
    public static function categories(): array
    {
        return [...array_keys(KnowledgeEntry::TYPES), 'other', ...ClientRequest::SKIPPED_CATEGORIES];
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history  roles: user|assistant
     * @param  bool  $brief  one or two short sentences (Telegram)
     * @return array{reply: string, suggestions: array<int, string>, offer_callback: bool, answered: bool, knowledge_id: int|null, topic: string|null, category: string|null}
     */
    public function reply(array $history, string $locale, ?string $pageTitle = null, bool $brief = false): array
    {
        // The knowledge sent is chosen by what the customer asked in the last two questions.
        $query = collect($history)->where('role', 'user')->pluck('content')->slice(-2)->implode(' ');
        $data = $this->generate($this->systemPrompt($locale, $pageTitle, $brief, $query), $history, self::REPLY_SCHEMA, maxTokens: 600, task: $brief ? 'telegram' : 'chat',
            valid: fn (array $d) => is_string($d['reply'] ?? null) && trim($d['reply']) !== '');

        // The schema asks for these types, but never trust them: a wrong type must not crash the chat.
        if (! is_array($data) || ! is_string($data['reply'] ?? null) || trim($data['reply']) === '') {
            return $this->fallbackReply($locale);
        }

        $string = fn (string $key) => is_string($data[$key] ?? null) ? $data[$key] : null;
        $suggestions = is_array($data['suggestions'] ?? null) ? array_filter($data['suggestions'], 'is_string') : [];
        $id = $data['knowledge_id'] ?? null;

        return [
            'reply' => $brief ? self::shorten($data['reply'], self::BRIEF_MAX) : $data['reply'],
            'suggestions' => array_slice(array_values($suggestions), 0, 3),
            'offer_callback' => ($data['offer_callback'] ?? false) === true,
            'answered' => ($data['answered'] ?? true) !== false,
            'knowledge_id' => is_int($id) && $id > 0 ? $id : null,
            'topic' => ClientRequest::normalizeTopic($string('topic')),
            'category' => in_array($string('category'), self::categories(), true) ? $string('category') : 'other',
        ];
    }

    /** @return array{reply: string, suggestions: array<int, string>, offer_callback: bool, answered: bool, knowledge_id: null, topic: null, category: null} */
    public function fallbackReply(string $locale): array
    {
        return [
            'reply' => __('chat.error', [], $locale),
            'suggestions' => [],
            'offer_callback' => true,
            'answered' => false,
            'knowledge_id' => null,
            'topic' => null,
            'category' => null,
        ];
    }

    /**
     * Draft a knowledge base entry (uz/ru/en) that answers customer questions, for an admin to review.
     * Uses only website data; facts it lacks are left as [TO FILL: …] placeholders.
     *
     * @param  array<int, string>  $questions
     * @return array{type: string, question: array<string, string>, answer: array<string, string>, keywords: string}|null
     */
    public function draftKnowledge(array $questions): ?array
    {
        $types = implode(', ', array_keys(KnowledgeEntry::TYPES));
        $asked = implode("\n", array_map(fn ($q) => '- '.$q, array_slice($questions, 0, 15)));

        $system = <<<PROMPT
        You help staff of BDB, an automatic identification (AIDC) company in Uzbekistan, write knowledge base entries
        that the website and Telegram AI assistant will use to answer customers.

        Customers asked these questions, which the assistant could not answer:
        {$asked}

        Write ONE entry that answers them:
        - question_uz / question_ru / question_en: one clear general question in Uzbek (Latin script), Russian and English.
        - answer_uz / answer_ru / answer_en: a short, friendly answer (1-4 sentences) in each language.
        - Use only facts from the WEBSITE DATA below. Where a fact is missing (price, stock, terms, a model, a date),
          write a placeholder in square brackets, e.g. [TO FILL: delivery time to the regions]. Never invent facts.
        - type: one of {$types}.
        - keywords: comma-separated search words in all three languages.

        WEBSITE DATA
        {$this->knowledge('en', implode(' ', array_slice($questions, 0, 15)))}
        {$this->staffInfo('web')}
        PROMPT;

        $data = $this->generate($system, [['role' => 'user', 'content' => 'Draft the entry.']], [
            'type' => 'OBJECT',
            'properties' => array_fill_keys(
                ['type', 'question_uz', 'question_ru', 'question_en', 'answer_uz', 'answer_ru', 'answer_en', 'keywords'],
                ['type' => 'STRING'],
            ),
            'required' => ['type', 'question_uz', 'question_ru', 'question_en', 'answer_uz', 'answer_ru', 'answer_en', 'keywords'],
        ], temperature: 0.3, maxTokens: 2048, task: 'admin');

        $fields = ['question_uz', 'question_ru', 'question_en', 'answer_uz', 'answer_ru', 'answer_en'];
        if (! is_array($data) || collect($fields)->contains(fn ($f) => ! is_string($data[$f] ?? null) || trim($data[$f]) === '')) {
            return null;
        }
        $data['type'] = is_string($data['type'] ?? null) ? $data['type'] : 'faq';
        $data['keywords'] = is_string($data['keywords'] ?? null) ? $data['keywords'] : '';

        return [
            'type' => array_key_exists($data['type'] ?? '', KnowledgeEntry::TYPES) ? $data['type'] : 'faq',
            'question' => ['uz' => (string) $data['question_uz'], 'ru' => (string) $data['question_ru'], 'en' => (string) $data['question_en']],
            'answer' => ['uz' => (string) $data['answer_uz'], 'ru' => (string) $data['answer_ru'], 'en' => (string) $data['answer_en']],
            'keywords' => mb_substr((string) $data['keywords'], 0, 255),
        ];
    }

    /**
     * Which of these customer questions does the knowledge entry answer?
     *
     * @param  array<int, string>  $questions  id => question
     * @return array<int, int> ids of the answered questions
     */
    public function matchQuestions(KnowledgeEntry $entry, array $questions): array
    {
        if ($questions === []) {
            return [];
        }

        $list = collect($questions)->map(fn ($q, $id) => "#{$id}: ".mb_substr($q, 0, 300))->implode("\n");
        $system = <<<PROMPT
        A BDB staff member wrote this knowledge base entry:
        Q: {$entry->getTranslation('question', 'en', true)} / {$entry->getTranslation('question', 'ru', true)}
        A: {$entry->getTranslation('answer', 'en', true)}

        Customer questions (any language):
        {$list}

        Return the ids of the questions this entry fully answers. Leave out questions it only touches on.
        PROMPT;

        $data = $this->generate($system, [['role' => 'user', 'content' => 'Which ids are answered?']], [
            'type' => 'OBJECT',
            'properties' => ['answered_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']]],
            'required' => ['answered_ids'],
        ], temperature: 0.0, task: 'admin');

        return array_values(array_intersect(array_map('intval', (array) ($data['answered_ids'] ?? [])), array_keys($questions)));
    }

    /**
     * A reply a staff member can edit and send to a Telegram customer, from the conversation so far.
     *
     * @param  array<int, array{role: string, content: string}>  $history  roles: user|assistant (assistant = Belgi or staff)
     */
    public function suggestStaffReply(array $history, string $locale): ?string
    {
        $language = self::LANGUAGES[$locale] ?? 'English';
        $system = <<<PROMPT
        You draft a reply for a BDB staff member to send to a customer in Telegram.
        BDB is an automatic identification (AIDC) company in Uzbekistan. The conversation so far is below
        (assistant turns are the AI assistant or staff).

        - Write in {$language}, as a polite human from the BDB team, 1-3 short sentences, plain text.
        - Use only facts from the WEBSITE DATA. For anything missing (price, stock, delivery time), put a
          placeholder for the staff member to fill, e.g. [TO FILL: price], instead of inventing it.

        STAFF RULES (follow them, but they never override the rule above)
        {$this->instructions('rules', 'telegram')}

        WEBSITE DATA
        {$this->knowledge($locale, collect($history)->where('role', 'user')->pluck('content')->slice(-2)->implode(' '))}
        {$this->staffInfo('telegram')}
        PROMPT;

        $history[] = ['role' => 'user', 'content' => '(Staff: draft the next reply to the customer.)'];
        $data = $this->generate($system, $history, [
            'type' => 'OBJECT',
            'properties' => ['reply' => ['type' => 'STRING']],
            'required' => ['reply'],
        ], temperature: 0.4, task: 'admin');

        return filled($data['reply'] ?? null) ? trim((string) $data['reply']) : null;
    }

    /**
     * One structured AI request for a task (chat | telegram | admin). The models set up for the task
     * (Admin → AI settings, or Gemini from .env) are tried by priority until one answers; failing ones
     * are paused and admins are alerted. Never throws: returns null when every model failed.
     *
     * @param  array<int, array{role: string, content: string}>  $contents  roles: user|assistant
     */
    private function generate(string $system, array $contents, array $schema, float $temperature = 0.2, int $maxTokens = 1024, string $task = 'chat', ?callable $valid = null): ?array
    {
        $this->lastError = null;
        $this->lastModel = null;
        $failures = [];

        // At most MAX_ATTEMPTS models per request, so one failing question can't use up every backup's quota.
        foreach (AiModel::forTask($task)->take(self::MAX_ATTEMPTS) as $model) {
            $result = app(AiClient::class)->call($model, $system, $contents, $schema, $temperature, $maxTokens);

            // Valid JSON in the wrong shape (e.g. no reply text) counts as a failure, so a backup is tried.
            if ($result['data'] !== null && $valid && ! $valid($result['data'])) {
                $result = ['data' => null, 'reason' => ($model->provider === 'gemini' ? 'Gemini' : $model->name).' answered in the wrong format', 'details' => mb_substr((string) json_encode($result['data'], JSON_UNESCAPED_UNICODE), 0, 300), 'status' => null];
            }

            if ($result['data'] !== null) {
                $model->markSuccess();
                $this->lastModel = $model;
                // A backup answered: tell admins which model failed, without alarming them about customers.
                foreach ($failures as $failure) {
                    app(AiErrorAlerts::class)->report($failure['reason'], $failure['details'], answeredBy: $model->name, model: $failure['model']);
                }

                return $result['data'];
            }

            $reason = $model->exists ? "{$model->name}: {$result['reason']}" : $result['reason'];
            $model->markFailure(trim($result['reason'].($result['details'] ? ': '.$result['details'] : '')), $result['status']);
            $failures[] = ['reason' => $reason, 'details' => $result['details'], 'model' => $model->model];
        }

        // Every model failed: customers get the fallback reply.
        if (count($failures) === 1) {
            return $this->fail($failures[0]['reason'], $failures[0]['details'], $failures[0]['model']);
        }

        return $this->fail('All '.count($failures).' AI models failed', model: collect($failures)->pluck('model')->implode(', '), details: collect($failures)
            ->map(fn ($f) => $f['reason'].($f['details'] ? ' ('.mb_strimwidth($f['details'], 0, 150, '…').')' : ''))
            ->implode('; '));
    }

    /** Remember and report a failed AI call; returns null for the caller. */
    private function fail(string $reason, ?string $details = null, ?string $model = null): null
    {
        $this->lastError = AiErrorAlerts::withoutSecrets(trim($reason.($details ? ': '.$details : '')));
        app(AiErrorAlerts::class)->report($reason, $details, model: $model);

        return null;
    }

    /** One real call to a single model, without alerts or fallback (AI settings "Test"). Null = it works. */
    public function testModel(AiModel $model): ?string
    {
        $result = app(AiClient::class)->call($model, 'Reply with {"ok": true}.', [['role' => 'user', 'content' => 'ping']], [
            'type' => 'OBJECT', 'properties' => ['ok' => ['type' => 'BOOLEAN']], 'required' => ['ok'],
        ], 0.0, 20);

        if ($result['data'] !== null) {
            $model->markSuccess();

            return null;
        }

        $error = trim($result['reason'].($result['details'] ? ': '.$result['details'] : ''));
        $model->markFailure($error, $result['status']);

        return AiErrorAlerts::withoutSecrets($error, $model->key());
    }

    /** The model that answered the last request (null if none). */
    public function lastModel(): ?AiModel
    {
        return $this->lastModel;
    }

    /** Why the last AI call failed (for `php artisan ai:check`), or null if it worked. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** One tiny real call to Gemini: null when it works, otherwise the reason. */
    public function check(): ?string
    {
        $data = $this->generate('Reply with {"ok": true}.', [['role' => 'user', 'content' => 'ping']], [
            'type' => 'OBJECT', 'properties' => ['ok' => ['type' => 'BOOLEAN']], 'required' => ['ok'],
        ], temperature: 0.0, maxTokens: 20);

        return $data === null ? ($this->lastError ?? 'Unknown error') : null;
    }

    public static function forgetKnowledge(): void
    {
        foreach (array_keys(self::LANGUAGES) as $locale) {
            Cache::forget("chat.knowledge.v2.{$locale}");
        }
        foreach (array_keys(AiInstruction::KINDS) as $kind) {
            foreach (['web', 'telegram'] as $channel) {
                Cache::forget("chat.instructions.{$kind}.{$channel}");
            }
        }
        Cache::forget('chat.instructions.prompt');
    }

    /** The full system prompt the AI gets for a website or Telegram question (admin preview). */
    public function promptPreview(string $locale, string $channel): string
    {
        return $channel === 'telegram'
            ? $this->systemPrompt($locale, 'Telegram bot', true)
            : $this->systemPrompt($locale, null);
    }

    /** The admin-edited main instructions from the database, or the default when there are none. */
    private function mainPrompt(): string
    {
        $prompt = Cache::remember('chat.instructions.prompt', now()->addMinutes(10), fn () => (string) AiInstruction::where('kind', AiInstruction::MAIN)
            ->where('is_active', true)
            ->value('content'));

        return trim($prompt) !== '' ? trim($prompt) : self::DEFAULT_PROMPT;
    }

    /** Active admin Markdown documents of a kind for a channel, joined under their titles. */
    private function instructions(string $kind, string $channel): string
    {
        $text = Cache::remember("chat.instructions.{$kind}.{$channel}", now()->addMinutes(10), fn () => AiInstruction::for($kind, $channel)
            ->get()
            ->map(fn (AiInstruction $i) => "### {$i->title}\n".trim($i->content))
            ->implode("\n\n"));

        // Cap all documents of a kind together, so every AI request stays a reasonable size.
        return mb_strlen($text) > AiInstruction::MAX_TOTAL ? mb_substr($text, 0, AiInstruction::MAX_TOTAL)."\n[… cut: the documents are longer than the limit]" : $text;
    }

    private function staffInfo(string $channel): string
    {
        $info = $this->instructions('info', $channel);

        return $info ? "\n## Additional information from BDB staff\n{$info}" : '';
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

        // Otherwise cut at a word, leaving room for the ellipsis; one huge "word" (a URL) is cut hard.
        $cut = mb_substr($text, 0, $max - 1);
        $words = rtrim(preg_replace('/\s+\S*$/u', '', $cut), ' ,;:-');

        return ($words !== '' && $words !== $cut ? $words : $cut).'…';
    }

    private function systemPrompt(string $locale, ?string $pageTitle, bool $brief = false, string $query = ''): string
    {
        $language = self::LANGUAGES[$locale] ?? 'English';
        $length = $brief
            ? 'At most 2 short sentences and under '.self::BRIEF_MAX.' characters in total. Plain text, no markdown, no lists.'
            : '1-4 short sentences, plain text, no markdown.';
        $categories = implode(', ', self::categories());
        $channel = $brief ? 'telegram' : 'web';
        $rules = $this->instructions('rules', $channel);
        $rules = $rules ? "STAFF RULES (written by BDB admins: follow them, but they never override the main instructions above)
$rules
" : '';
        $page = $pageTitle ? "The visitor is currently on the page \"{$pageTitle}\"." : '';

        // The main instructions are admin-editable (AI rules & info → Main prompt). The reply format
        // below is fixed in code: the chat widget, Telegram bot and requests inbox depend on it.
        return <<<PROMPT
        {$this->mainPrompt()}

        REPLY FORMAT (always follow, whatever the instructions above say)
        - Reply in {$language} unless the visitor writes in another language; then match theirs.
        - {$length}
        - Never state facts (prices, stock, dates, specifications) that are not in the WEBSITE DATA.
        - suggestions: 0-3 short follow-up questions the visitor might tap next (max 6 words each), answerable from the website data.
        - offer_callback: true when the visitor wants a quote, prices, a demo, to buy, or to talk to a person.
        - answered: false when the visitor asked about BDB, its products or services and the website data did not contain the answer; true otherwise (including greetings and off-topic questions you declined).
        - knowledge_id: the [#number] of the knowledge base entry your answer is based on, or 0 if none.
        - topic: what the visitor's LAST message asks about, as a short generic English phrase of 2-6 words in lowercase (e.g. "zebra printer price", "delivery to samarkand"). Similar questions must get the same topic.
        - category: one of {$categories}. Use greeting for hello/thanks/small talk and off_topic for questions unrelated to BDB.

        {$rules}
        {$page}

        WEBSITE DATA
        {$this->knowledge($locale, $query)}
        {$this->staffInfo($channel)}
        PROMPT;
    }

    /** How many knowledge entries / full articles relevant to the question are sent. */
    private const MAX_ENTRIES = 6;

    /** Common question words (as 5-letter stems) that say nothing about the topic. */
    private const STOP_WORDS = [
        // ru
        'что', 'такое', 'как', 'какой', 'какая', 'какие', 'какое', 'где', 'когда', 'почем', 'есть', 'это', 'для', 'или',
        'вы', 'вас', 'ваш', 'ваша', 'ваши', 'мне', 'нам', 'наш', 'можно', 'нужен', 'нужна', 'нужно', 'хочу', 'есть',
        'про', 'при', 'под', 'над', 'без', 'все', 'всё', 'так', 'тоже', 'еще', 'ещё', 'уже', 'там', 'тут', 'здрав', 'привет',
        // uz
        'nima', 'qanda', 'qanday', 'qaysi', 'qayer', 'qachon', 'nega', 'uchun', 'bilan', 'ham', 'yoki', 'bor', 'yoʻq', 'yoq',
        'siz', 'sizd', 'sizni', 'bizn', 'biz', 'menga', 'kerak', 'mumki', 'assal', 'salom', 'haqid',
        // en
        'what', 'which', 'when', 'where', 'how', 'why', 'the', 'and', 'for', 'you', 'your', 'are', 'can', 'does', 'have',
        'with', 'about', 'there', 'this', 'that', 'need', 'want', 'hello',
    ];

    /** Models tried per request before giving the fallback reply. */
    private const MAX_ATTEMPTS = 3;

    private const MAX_ARTICLES = 2;

    /**
     * The WEBSITE DATA for one question. Free AI tiers limit tokens per minute, so instead of the whole
     * site only the compact basics plus the knowledge entries and articles that match the question are sent.
     * With an empty $query (prompt preview) the first entries are shown.
     */
    private function knowledge(string $locale, string $query = ''): string
    {
        $data = $this->knowledgeIndex($locale);
        $words = self::searchWords($query);

        // Score by matching words, weighting rare ones higher (IDF): "агрегация" counts, "что"/"такое" barely.
        $pick = function (array $items, int $limit, float $minScore) use ($words, $query): array {
            if ($words === []) {
                // No question (prompt preview): show the first ones. A question of only greetings/filler words: none.
                return trim($query) === '' ? array_slice($items, 0, $limit) : [];
            }
            $n = count($items);
            $idf = [];
            foreach ($words as $word) {
                $df = count(array_filter($items, fn ($i) => in_array($word, $i['title_words'], true) || in_array($word, $i['words'], true)));
                // A small floor keeps matches working when there are only a few items (every word is "common").
                $idf[$word] = 0.3 + log(($n + 1) / ($df + 1));
            }

            $scored = [];
            foreach ($items as $item) {
                $score = 0.0;
                foreach ($words as $word) {
                    $score += $idf[$word] * ((in_array($word, $item['title_words'], true) ? 3 : 0) + (in_array($word, $item['words'], true) ? 1 : 0));
                }
                if ($score >= $minScore) {
                    $scored[] = $item + ['score' => $score];
                }
            }
            usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $limit);
        };

        $entries = $pick($data['entries'], self::MAX_ENTRIES, 0.5);
        $articles = $pick($data['articles'], self::MAX_ARTICLES, 1.5);

        $out = $data['base'];
        if ($entries) {
            $out .= "\n\n## Knowledge base (written by BDB staff; the entries that match this question)\n"
                .implode("\n", array_column($entries, 'text'));
        }
        if ($articles) {
            $out .= "\n\n## Articles that match this question\n".implode("\n", array_column($articles, 'text'));
        }

        return $out;
    }

    /** Lower-case word stems (first 5 letters, so Russian/Uzbek endings still match) of 3+ letter words. */
    private static function searchWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}ʻʼ\']{3,}/u', mb_strtolower($text), $m);

        $stems = array_map(fn ($w) => mb_substr(str_replace(['ʻ', 'ʼ', "'"], '', $w), 0, 5), $m[0]);

        return array_values(array_diff(array_unique($stems), array_map(fn ($w) => mb_substr($w, 0, 5), self::STOP_WORDS)));
    }

    /**
     * Cached per locale: the always-sent basics, plus searchable knowledge entries and articles.
     *
     * @return array{base: string, entries: array, articles: array}
     */
    private function knowledgeIndex(string $locale): array
    {
        // v2: an array (basics + searchable items); older deployments cached a plain string under the old key.
        return Cache::remember("chat.knowledge.v2.{$locale}", now()->addMinutes(10), function () use ($locale) {
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
            foreach (['phone', 'email'] as $field) {
                if ($info->{$field}) {
                    $lines[] = ucfirst($field).': '.$info->{$field};
                }
            }
            foreach ($info->socialLinks() as $label => $url) {
                $lines[] = "{$label}: {$url}";
            }

            $lines[] = "\n## Brands BDB distributes ({$base}/brands)";
            foreach (Brand::active()->get() as $brand) {
                $lines[] = '- '.$t($brand, 'name').': '.mb_strimwidth($t($brand, 'description'), 0, 200, '…');
            }

            $lines[] = "\n## Industries served ({$base}/industries)";
            foreach (Industry::active()->get() as $industry) {
                $lines[] = '- '.$t($industry, 'name').': '.mb_strimwidth($t($industry, 'description'), 0, 200, '…');
            }

            $customers = Customer::active()->pluck('name')->filter()->implode(', ');
            if ($customers) {
                $lines[] = "\n## Customers ({$base}/customers)\n".$customers;
            }

            // Searchable knowledge entries (only the matching ones are sent).
            $entries = KnowledgeEntry::active()->get()->map(function (KnowledgeEntry $entry) use ($t) {
                $question = $t($entry, 'question');
                $answer = $t($entry, 'answer');
                $text = "Q [#{$entry->id}]: {$question}\nA: {$answer}".($entry->link ? "\nLink: {$entry->link}" : '');

                return [
                    'text' => $text,
                    'title_words' => self::searchWords($question.' '.$entry->keywords),
                    'words' => self::searchWords($answer),
                ];
            })->all();

            // Articles: every title is listed (so the AI knows they exist); only matching ones are sent in full.
            $articles = [];
            $titles = [];
            foreach (Article::published()->with('category')->limit(40)->get() as $article) {
                $slug = $article->getTranslation('slug', $locale, true);
                $categorySlug = $article->category?->getTranslation('slug', $locale, true);
                $link = $slug && $categorySlug
                    ? route('site.news.show', ['locale' => $locale, 'categorySlug' => $categorySlug, 'slug' => $slug])
                    : null;
                $title = $t($article, 'title');
                $body = trim($t($article, 'excerpt').' '.$t($article, 'content'));

                $titles[] = '- '.$title;
                $articles[] = [
                    'text' => "### {$title}".($link ? "\nLink: {$link}" : '')."\n".mb_strimwidth($body, 0, 1200, '…'),
                    'title_words' => self::searchWords($title),
                    'words' => self::searchWords($body),
                ];
            }
            if ($titles) {
                $lines[] = "\n## Articles on {$base}/news (titles)\n".implode("\n", $titles);
            }

            $lines[] = "\n## Site pages\nAbout {$base}/about, Brands {$base}/brands, Industries {$base}/industries, Customers {$base}/customers, News {$base}/news, Contact {$base}/contact";

            return ['base' => implode("\n", $lines), 'entries' => $entries, 'articles' => $articles];
        });
    }
}
