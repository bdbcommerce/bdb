<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ClientRequest;
use App\Models\CompanyInfo;
use App\Models\KnowledgeEntry;
use App\Services\ChatAssistant;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: only the knowledge relevant to the question is sent to the AI. */
class ChatKnowledgeRetrievalQaTest extends TestCase
{
    use RefreshDatabase;

    private const REPLY = [
        'reply' => 'ok', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
        'knowledge_id' => 0, 'topic' => 'test topic', 'category' => 'faq',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-key', 'services.telegram.bot_token' => null]);
    }

    private function fakeGemini(array $json = self::REPLY): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function chat(string $text, string $locale = 'en')
    {
        return $this->postJson('/chat/message', [
            'locale' => $locale, 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => $text]],
        ])->assertOk();
    }

    private function lastPrompt(): string
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->last()[0]['system_instruction']['parts'][0]['text'];
    }

    /** @return array<int, int> knowledge entry ids in the prompt */
    private function entryIds(string $prompt): array
    {
        preg_match_all('/Q \[#(\d+)\]/', $prompt, $m);

        return array_map('intval', $m[1]);
    }

    private function knowledge(string $locale, string $query): string
    {
        return (new \ReflectionMethod(ChatAssistant::class, 'knowledge'))->invoke(app(ChatAssistant::class), $locale, $query);
    }

    private function seedDemo(): void
    {
        $this->seed([ArticleCategorySeeder::class, DemoContentSeeder::class]);
    }

    private function entryId(string $enQuestion): int
    {
        return KnowledgeEntry::all()->first(fn ($e) => $e->getTranslation('question', 'en') === $enQuestion)->id;
    }

    public function test_relevant_entry_ids_are_sent_in_every_locale(): void
    {
        $this->seedDemo();
        $asl = $this->entryId('What is Asl Belgi?');
        $price = $this->entryId('How much does it cost?');
        $repair = $this->entryId('Do you repair equipment?');
        $delivery = $this->entryId('What about delivery and warranty?');
        $this->fakeGemini();

        $cases = [
            ['ru', 'Что такое Асл Белги?', $asl], ['uz', 'Asl Belgi nima?', $asl], ['en', 'What is Asl Belgi?', $asl],
            ['ru', 'Сколько стоит сканер?', $price], ['uz', 'narxi qancha?', $price],
            ['ru', 'ремонт принтера', $repair], ['uz', 'Uskunani taʼmirlaysizmi?', $repair], ['uz', "Uskunani ta'mirlaysizmi?", $repair],
            ['ru', 'доставка в Самарканд и гарантия', $delivery], ['uz', 'Yetkazib berish va kafolat', $delivery],
        ];
        foreach ($cases as [$locale, $question, $id]) {
            $this->chat($question, $locale);
            $prompt = $this->lastPrompt();
            $this->assertContains($id, $this->entryIds($prompt), "[{$locale}] {$question}");
            $this->assertLessThanOrEqual(6, count($this->entryIds($prompt)));
            $this->assertLessThanOrEqual(2, substr_count(Str::after($prompt, '## Articles that match'), "\n### "));
        }
    }

    public function test_relevant_article_is_sent_in_full(): void
    {
        $this->seedDemo();
        $aggregation = Article::all()->first(fn ($a) => str_starts_with($a->getTranslation('title', 'en'), 'Aggregation'));
        $this->fakeGemini();

        $this->chat('Агрегация коробов и паллет', 'ru');

        $this->assertStringContainsString('### '.$aggregation->getTranslation('title', 'ru'), Str::after($this->lastPrompt(), '## Articles that match'));
    }

    public function test_knowledge_id_from_the_ai_still_links_the_client_request(): void
    {
        $entry = KnowledgeEntry::create([
            'type' => 'delivery', 'is_active' => true,
            'question' => ['uz' => 'Samarqandga yetkazasizmi?', 'ru' => 'Доставляете в Самарканд?', 'en' => 'Do you deliver to Samarkand?'],
            'answer' => ['uz' => 'Ha.', 'ru' => 'Да.', 'en' => 'Yes.'],
        ]);
        $this->fakeGemini(['knowledge_id' => $entry->id, 'topic' => 'delivery to samarkand', 'category' => 'delivery'] + self::REPLY);

        $this->chat('Доставляете в Самарканд?', 'ru');

        $this->assertContains($entry->id, $this->entryIds($this->lastPrompt()));
        $this->assertSame($entry->id, ClientRequest::where('topic', 'delivery to samarkand')->value('knowledge_entry_id'));
    }

    public function test_unrelated_or_filler_questions_send_no_entries_or_articles(): void
    {
        $this->seedDemo();
        $this->fakeGemini();

        foreach ([['ru', 'Какая погода в Ташкенте завтра?'], ['ru', 'Привет'], ['en', 'hello, how are you'], ['en', '👍👍 ok 😀'], ['uz', 'Salom']] as [$locale, $question]) {
            $this->chat($question, $locale);
            $prompt = $this->lastPrompt();
            $this->assertSame([], $this->entryIds($prompt), $question);
            $this->assertStringNotContainsString('## Articles that match', $prompt, $question);
            // The basics are always there.
            $this->assertStringContainsString('## Articles on', $prompt);
            $this->assertStringContainsString('## Site pages', $prompt);
        }
    }

    public function test_empty_query_shows_the_first_items_for_the_prompt_preview(): void
    {
        $this->seedDemo();

        $k = $this->knowledge('en', '');

        $this->assertCount(6, $this->entryIds($k));
        $this->assertSame(2, substr_count(Str::after($k, '## Articles that match'), "\n### "));
    }

    public function test_query_uses_only_the_last_two_user_messages(): void
    {
        $this->seedDemo();
        $asl = $this->entryId('What is Asl Belgi?');
        $this->fakeGemini();

        $this->postJson('/chat/message', ['locale' => 'en', 'conversation' => (string) Str::uuid(), 'messages' => [
            ['role' => 'user', 'content' => 'What is Asl Belgi?'],
            ['role' => 'assistant', 'content' => 'Asl Belgi is marking.'],
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi'],
            ['role' => 'user', 'content' => 'hello again'],
        ]])->assertOk();
        $this->assertNotContains($asl, $this->entryIds($this->lastPrompt()));

        $this->postJson('/chat/message', ['locale' => 'en', 'conversation' => (string) Str::uuid(), 'messages' => [
            ['role' => 'user', 'content' => 'What is Asl Belgi?'],
            ['role' => 'assistant', 'content' => 'Asl Belgi is marking.'],
            ['role' => 'user', 'content' => 'And how much?'],
        ]])->assertOk();
        $this->assertContains($asl, $this->entryIds($this->lastPrompt()));
    }

    public function test_very_long_and_mixed_input_is_handled(): void
    {
        $this->seedDemo();
        $this->fakeGemini();

        $this->chat(mb_substr(str_repeat('Нужны сканеры штрихкодов ZT411 для склада и маркировка 😀 ', 30), 0, 1000), 'ru');
        $prompt = $this->lastPrompt();

        $this->assertLessThanOrEqual(6, count($this->entryIds($prompt)));
        $this->assertLessThan(12000, mb_strlen($prompt));
    }

    public function test_saving_entries_articles_and_company_info_flushes_the_cached_index(): void
    {
        $this->seedDemo();
        $this->fakeGemini();
        $category = ArticleCategory::first();

        $warm = function () {
            foreach (['uz', 'ru', 'en'] as $l) {
                $this->knowledge($l, 'x');
                $this->assertTrue(Cache::has("chat.knowledge.v2.{$l}"));
            }
        };
        $flushed = function (string $what) {
            foreach (['uz', 'ru', 'en'] as $l) {
                $this->assertFalse(Cache::has("chat.knowledge.v2.{$l}"), "{$what} {$l}");
            }
        };

        $warm();
        $entry = KnowledgeEntry::create(['type' => 'faq', 'is_active' => true,
            'question' => ['uz' => 'Zebrakvant?', 'ru' => 'Зебраквант?', 'en' => 'Zebraquant?'], 'answer' => ['uz' => 'Ha', 'ru' => 'Да', 'en' => 'Yes']]);
        $flushed('entry created');

        $warm();
        $entry->update(['keywords' => 'flamingo']);
        $flushed('entry updated');
        $this->chat('flamingo', 'en');
        $this->assertContains($entry->id, $this->entryIds($this->lastPrompt()));

        $warm();
        $article = Article::create(['article_category_id' => $category->id, 'is_published' => true, 'published_at' => now(),
            'title' => ['uz' => 'Kvazarlar', 'ru' => 'Квазары', 'en' => 'Quasars'], 'slug' => ['uz' => 'kvazar', 'ru' => 'kvazary', 'en' => 'quasars'],
            'excerpt' => ['en' => 'x'], 'content' => ['en' => 'y']]);
        $flushed('article created');
        $this->chat('quasars', 'en');
        $this->assertStringContainsString('### Quasars', Str::after($this->lastPrompt(), '## Articles that match'));

        $warm();
        $entry->delete();
        $flushed('entry deleted');

        $warm();
        CompanyInfo::updateOrCreate(['id' => 1], ['phone' => '+998 90 000 00 00']);
        $flushed('company info saved');
    }

    public function test_performance_with_300_entries_and_300_articles(): void
    {
        $category = ArticleCategory::create(['name' => ['en' => 'News'], 'slug' => ['uz' => 'news', 'ru' => 'news', 'en' => 'news']]);
        $words = ['scanner', 'printer', 'terminal', 'rfid', 'marking', 'warehouse', 'delivery', 'warranty', 'repair', 'price', 'label', 'barcode'];
        for ($i = 1; $i <= 300; $i++) {
            $w = $words[$i % 12].' '.$words[($i * 7) % 12];
            KnowledgeEntry::create(['type' => 'faq', 'is_active' => true, 'keywords' => "kw{$i}, {$w}",
                'question' => ['uz' => "Savol {$i} {$w}", 'ru' => "Вопрос {$i} {$w}", 'en' => "Question {$i} about {$w}"],
                'answer' => ['uz' => str_repeat("javob {$w} ", 20), 'ru' => str_repeat("ответ {$w} ", 20), 'en' => str_repeat("answer {$w} ", 20)]]);
            Article::create(['article_category_id' => $category->id, 'is_published' => true, 'published_at' => now()->subMinutes($i),
                'title' => ['uz' => "Maqola {$i} {$w}", 'ru' => "Статья {$i} {$w}", 'en' => "Article {$i} {$w}"],
                'slug' => ['uz' => "m{$i}", 'ru' => "s{$i}", 'en' => "a{$i}"],
                'excerpt' => ['en' => "Excerpt {$w}"], 'content' => ['en' => str_repeat("<p>Body about {$w} and more text.</p>", 30)]]);
        }
        $this->fakeGemini();

        DB::enableQueryLog();
        $t = microtime(true);
        $this->chat('Need a barcode scanner and printer with warranty', 'en');
        $cold = microtime(true) - $t;
        $coldQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $t = microtime(true);
        $this->chat(mb_substr(str_repeat('rfid marking warehouse delivery repair price label terminal unique'.Str::random(6).' ', 40), 0, 1000), 'en');
        $warm = microtime(true) - $t;
        $warmQueries = count(DB::getQueryLog());

        fwrite(STDERR, sprintf("\n[perf] cold %.0f ms / %d queries, warm %.0f ms / %d queries, prompt %d chars\n", $cold * 1000, $coldQueries, $warm * 1000, $warmQueries, mb_strlen($this->lastPrompt())));

        $this->assertLessThan(3.0, $cold);
        $this->assertLessThan(1.0, $warm);
        $this->assertLessThan(40, $coldQueries, 'no N+1 when building the index');
        $this->assertLessThanOrEqual(6, count($this->entryIds($this->lastPrompt())));
        $this->assertLessThan(12000, mb_strlen($this->lastPrompt()));
    }

    public function test_prompt_sizes_per_locale_for_real_questions(): void
    {
        $this->seedDemo();
        $this->fakeGemini();
        $questions = ['uz' => 'Asl Belgi uchun qanday printer kerak?', 'ru' => 'Какой принтер нужен для маркировки Асл Белги?', 'en' => 'Which printer do I need for Asl Belgi marking?'];

        foreach ($questions as $locale => $question) {
            $this->chat($question, $locale);
            $size = mb_strlen($this->lastPrompt());
            fwrite(STDERR, "\n[size] {$locale}: {$size} chars");
            // Roughly 2.6k tokens at ~3.5 characters per token, well under a 6k tokens/minute free tier.
            $this->assertLessThan(12000, $size, $locale);
        }
    }

    private function groq(int $n): AiModel
    {
        return AiModel::create([
            'name' => "Groq {$n}", 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile',
            'base_url' => "https://m{$n}.example.test/v1", 'api_key' => "secret-{$n}",
            'tasks' => ['chat', 'telegram', 'admin'], 'priority' => $n, 'is_active' => true,
        ]);
    }

    public function test_at_most_three_models_are_tried_per_request(): void
    {
        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->groq($n);
        }
        Http::fake(['*.example.test/*' => Http::response(['error' => ['message' => 'Rate limit']], 429), 'api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->chat('What is Asl Belgi?')->assertJsonStructure(['reply']);

        $called = Http::recorded(fn (Request $r) => str_contains($r->url(), 'example.test'))->map(fn ($p) => parse_url($p[0]->url(), PHP_URL_HOST))->values()->all();
        $this->assertSame(['m1.example.test', 'm2.example.test', 'm3.example.test'], $called);
    }

    public function test_reply_in_the_wrong_shape_falls_through_to_the_backup_model(): void
    {
        $this->groq(1);
        $this->groq(2);
        $ok = ['choices' => [['message' => ['content' => json_encode(['reply' => 'From backup'] + self::REPLY)], 'finish_reason' => 'stop']]];
        $bad = ['choices' => [['message' => ['content' => json_encode(['answer' => 'no reply key'])], 'finish_reason' => 'stop']]];
        Http::fake(['m1.example.test/*' => Http::response($bad), 'm2.example.test/*' => Http::response($ok), 'api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->chat('Hi')->assertJson(['reply' => 'From backup']);
    }

    public function test_chat_reply_asks_for_at_most_600_tokens(): void
    {
        $this->groq(1);
        $ok = ['choices' => [['message' => ['content' => json_encode(self::REPLY)], 'finish_reason' => 'stop']]];
        Http::fake(['m1.example.test/*' => Http::response($ok)]);

        $this->chat('Hi');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'm1.example.test') && in_array(600, [$r['max_tokens'] ?? null, $r['max_completion_tokens'] ?? null], true));
    }
}
