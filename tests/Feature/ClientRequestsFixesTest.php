<?php

namespace Tests\Feature;

use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Services\ChatAssistant;
use App\Services\ClientRequestResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Re-verification of the client request fixes: notify lock, AI type safety, draft validation.
 */
class ClientRequestsFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => 'test-key', 'services.telegram.bot_token' => 'BOT']);
    }

    private function fakeGemini(array $json): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function entry(): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'type' => 'delivery', 'is_active' => true,
            'question' => ['uz' => 'Samarqandga yetkazasizmi?', 'ru' => 'Доставляете в Самарканд?', 'en' => 'Do you deliver?'],
            'answer' => ['uz' => 'Ha.', 'ru' => 'Да.', 'en' => 'Yes.'],
        ]);
    }

    private function clientRequest(KnowledgeEntry $entry): ClientRequest
    {
        return ClientRequest::create([
            'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru', 'question' => 'Доставляете?',
            'status' => 'resolved', 'topic' => 'delivery', 'category' => 'delivery', 'knowledge_entry_id' => $entry->id,
        ]);
    }

    public function test_notify_lock_is_released_when_sending_throws(): void
    {
        $entry = $this->entry();
        $request = $this->clientRequest($entry);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->mock(ClientRequestResolver::class)->shouldReceive('notifyCustomers')->once()->andThrow(new \RuntimeException('boom'));
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($admin)->post(route('admin.client-requests.notify', $request), ['knowledge_entry_id' => $entry->id]);
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $lock = Cache::lock("client-requests.notify.{$entry->id}", 120);
        $this->assertTrue($lock->get(), 'lock still held after an exception');
        $lock->release();
    }

    public function test_notify_lock_is_released_after_a_normal_send_and_is_per_entry(): void
    {
        $entry = $this->entry();
        $other = $this->entry();
        $request = $this->clientRequest($entry);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->fakeGemini([]);

        // A lock on another entry does not block this one.
        $foreign = Cache::lock("client-requests.notify.{$other->id}", 120);
        $foreign->get();

        $this->actingAs($admin)->post(route('admin.client-requests.notify', $request), ['knowledge_entry_id' => $entry->id])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'already being sent'));
        $foreign->release();

        $lock = Cache::lock("client-requests.notify.{$entry->id}", 120);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_reply_flags_require_real_booleans_and_suggestions_strings(): void
    {
        $this->fakeGemini(['reply' => 'Hi', 'offer_callback' => 'true', 'answered' => 0, 'suggestions' => ['a', 1, ['x'], null, 'b', 'c', 'd'],
            'knowledge_id' => '3', 'topic' => 5, 'category' => true]);
        $r = app(ChatAssistant::class)->reply([['role' => 'user', 'content' => 'Hi']], 'en');

        $this->assertSame('Hi', $r['reply']);
        $this->assertFalse($r['offer_callback']);
        $this->assertTrue($r['answered']);
        $this->assertSame(['a', 'b', 'c'], $r['suggestions']);
        $this->assertNull($r['knowledge_id']);
        $this->assertSame('other', $r['category']);

        Http::swap(new Factory(app('events')));
        $this->fakeGemini(['reply' => 'Hi', 'offer_callback' => true, 'answered' => false, 'knowledge_id' => 7]);
        $r = app(ChatAssistant::class)->reply([['role' => 'user', 'content' => 'Hi']], 'en');
        $this->assertTrue($r['offer_callback']);
        $this->assertFalse($r['answered']);
        $this->assertSame(7, $r['knowledge_id']);
    }

    public function test_whitespace_reply_falls_back(): void
    {
        $this->fakeGemini(['reply' => "  \n "]);
        $assistant = app(ChatAssistant::class);
        $this->assertSame($assistant->fallbackReply('ru'), $assistant->reply([['role' => 'user', 'content' => 'Привет']], 'ru'));
    }

    public function test_draft_requires_all_six_text_fields_and_defaults_type_and_keywords(): void
    {
        $full = ['question_uz' => 'Savol', 'question_ru' => 'Вопрос', 'question_en' => 'Q', 'answer_uz' => 'Javob', 'answer_ru' => 'Ответ', 'answer_en' => 'A'];

        foreach (['question_uz' => '', 'answer_ru' => '   ', 'question_en' => 5, 'answer_uz' => ['x']] as $field => $bad) {
            Http::swap(new Factory(app('events')));
            $this->fakeGemini([$field => $bad] + $full);
            $this->assertNull(app(ChatAssistant::class)->draftKnowledge(['Q']), "draft accepted with bad {$field}");
        }

        Http::swap(new Factory(app('events')));
        $this->fakeGemini($full + ['type' => ['faq'], 'keywords' => 12]);
        $draft = app(ChatAssistant::class)->draftKnowledge(['Q']);
        $this->assertNotNull($draft);
        $this->assertSame('faq', $draft['type']);
        $this->assertIsString($draft['keywords']);
    }
}
