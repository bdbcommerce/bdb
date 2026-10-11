<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Services\ChatAssistant;
use Database\Seeders\ArticleCategorySeeder;
use Database\Seeders\DemoContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class ChatKnowledgeRetrievalFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ArticleCategorySeeder::class, DemoContentSeeder::class]);
    }

    private function knowledge(string $locale, string $query): string
    {
        return (new ReflectionMethod(ChatAssistant::class, 'knowledge'))->invoke(app(ChatAssistant::class), $locale, $query);
    }

    /** @return array<int, string> ids of knowledge entries sent */
    private function entries(string $prompt): array
    {
        preg_match_all('/Q \[#(\d+)\]/', $prompt, $m);

        return $m[1];
    }

    private function sendsArticles(string $prompt): bool
    {
        return str_contains($prompt, 'Articles that match this question');
    }

    public function test_uzbek_typed_in_cyrillic_finds_the_latin_uzbek_knowledge(): void
    {
        $prompt = $this->knowledge('uz', 'Асл белги нима?');

        $this->assertNotEmpty($this->entries($prompt));
        $this->assertStringContainsString('Asl Belgi', $prompt);
        $this->assertTrue($this->sendsArticles($prompt));
    }

    public function test_word_initial_cyrillic_ye_is_transliterated(): void
    {
        $this->assertSame('yetkazib beryapsizmi', (new ReflectionMethod(ChatAssistant::class, 'uzLatin'))->invoke(null, 'Етказиб беряпсизми'));
        $this->assertContains('8', $this->entries($this->knowledge('uz', 'Етказиб берасизми?')));
    }

    public function test_single_keyword_product_questions_still_get_the_equipment_entry(): void
    {
        foreach ([['ru', 'Есть принтеры?'], ['uz', 'Skaner bormi?']] as [$locale, $question]) {
            $this->assertContains('3', $this->entries($this->knowledge($locale, $question)), $question);
        }
    }

    public function test_one_incidental_body_word_does_not_pull_in_an_article(): void
    {
        foreach ([['en', 'Do you sell pizza delivery in Tashkent?'], ['uz', 'Yetkazib berish va kafolat']] as [$locale, $question]) {
            $this->assertFalse($this->sendsArticles($this->knowledge($locale, $question)), $question);
        }
    }

    public function test_stop_words_are_whole_words_not_stems(): void
    {
        $words = (new ReflectionMethod(ChatAssistant::class, 'searchWords'))->invoke(null, 'Привет! Можете привезти? Salomatlik sizda bor');

        $this->assertContains('приве', $words, '"привезти" is kept although "привет" is a stop word');
        $this->assertContains('salom', $words, '"salomatlik" is kept although "salom" is a stop word');
        $this->assertNotContains('sizda', $words);
        $this->assertCount(2, $words);
    }

    public function test_every_uzbek_apostrophe_and_short_terms_with_digits_count(): void
    {
        $search = new ReflectionMethod(ChatAssistant::class, 'searchWords');

        foreach (['taʼmirlash', "ta'mirlash", 'ta’mirlash', 'ta‘mirlash', 'ta`mirlash'] as $word) {
            $this->assertSame(['tamir'], $search->invoke(null, $word), $word);
        }
        $this->assertSame(['1c', 'qr', '2d'], $search->invoke(null, '1C QR 2D a b'));
        $this->assertSame('6', $this->entries($this->knowledge('en', 'Can I connect the scanner to 1C?'))[0] ?? null);
    }

    public function test_older_articles_beyond_the_title_list_are_still_searchable(): void
    {
        $category = ArticleCategory::first();
        $old = Article::create([
            'article_category_id' => $category->id,
            'title' => ['uz' => 'Xyzzyquux eski', 'ru' => 'Ксизикукс старая', 'en' => 'Xyzzyquux oldest article'],
            'slug' => ['uz' => 'old-uz', 'ru' => 'old-ru', 'en' => 'old-en'],
            'excerpt' => ['uz' => 'x', 'ru' => 'x', 'en' => 'About xyzzyquux.'],
            'content' => ['uz' => '<p>x</p>', 'ru' => '<p>x</p>', 'en' => '<p>Xyzzyquux details.</p>'],
            'is_published' => true, 'published_at' => now()->subYears(3),
        ]);
        foreach (range(1, 25) as $i) {
            $old->replicate()->fill([
                'title' => ['uz' => "Yangi {$i}", 'ru' => "Новая {$i}", 'en' => "New {$i}"],
                'slug' => ['uz' => "n{$i}", 'ru' => "n{$i}", 'en' => "n{$i}"],
                'excerpt' => ['uz' => 'x', 'ru' => 'x', 'en' => 'x'],
                'content' => ['uz' => '<p>x</p>', 'ru' => '<p>x</p>', 'en' => '<p>x</p>'],
                'published_at' => now(),
            ])->save();
        }

        $prompt = $this->knowledge('en', 'Tell me about xyzzyquux');

        $this->assertSame(40, substr_count(Str::between($prompt, '(titles)', '## Site pages'), "\n- "));
        $this->assertStringContainsString('### Xyzzyquux oldest article', $prompt);
    }
}
