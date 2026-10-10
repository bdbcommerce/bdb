<?php

namespace Tests\Feature;

use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiErrorAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyTESTKEY1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => self::KEY,
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100555',
            'services.telegram.alert_chat_id' => '777',
            'services.telegram.webhook_secret' => 'test_webhook_secret_123',
        ]);
    }

    private function ask(string $text = 'Сколько стоит сканер?')
    {
        return $this->postJson('/chat/message', [
            'locale' => 'ru', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => $text]],
        ])->assertOk();
    }

    /** @return Collection<int, Request> */
    private function alerts()
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '777')
            ->map(fn ($pair) => $pair[0])->values();
    }

    public function test_quota_error_alerts_admins_once_and_customer_gets_fallback(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => [
                'code' => 429, 'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'You exceeded your current quota. key='.self::KEY,
            ]], 429),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertJson(['reply' => __('chat.error', [], 'ru'), 'offer_callback' => true]);
        $this->ask('Ещё вопрос');

        $alerts = $this->alerts();
        $this->assertCount(1, $alerts, 'repeats are throttled');
        $this->assertStringContainsString('AI assistant error', $alerts[0]['text']);
        $this->assertStringContainsString('HTTP 429 RESOURCE_EXHAUSTED', $alerts[0]['text']);
        $this->assertStringContainsString('You exceeded your current quota', $alerts[0]['text']);
        $this->assertStringNotContainsString(self::KEY, $alerts[0]['text']);
        $this->assertSame('HTML', $alerts[0]['parse_mode']);

        // After the mute window the same problem is reported again.
        $this->travel(16)->minutes();
        $this->ask();
        $this->assertCount(2, $this->alerts());
    }

    public function test_missing_key_timeout_and_unusable_answer_are_reported(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->pushFailedConnection()
                ->push(['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => []]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask();
        $this->ask();
        config(['services.gemini.key' => null]);
        $this->ask();

        $texts = $this->alerts()->pluck('text')->implode("\n---\n");
        $this->assertStringContainsString('Gemini did not respond', $texts);
        $this->assertStringContainsString('Gemini gave no usable answer', $texts);
        $this->assertStringContainsString('SAFETY', $texts);
        $this->assertStringContainsString('GEMINI_API_KEY is not set', $texts);
    }

    public function test_alerts_go_to_the_sales_chat_when_no_alert_chat_is_set(): void
    {
        config(['services.telegram.alert_chat_id' => null]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === '-100555' && str_contains($r['text'], 'API key not valid'));
    }

    public function test_no_alert_without_telegram_and_none_when_the_ai_works(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => 'ok', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
                'knowledge_id' => 0, 'topic' => 'x', 'category' => 'faq',
            ])]]]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $this->ask();
        $this->assertCount(0, $this->alerts());

        config(['services.telegram.bot_token' => null, 'services.gemini.key' => null]);
        $this->ask();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org'));
    }

    public function test_telegram_bot_failure_is_reported_too(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']], 429),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => 'done', 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);

        $this->postJson('/telegram/webhook', [
            'update_id' => 1,
            'message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555], 'text' => 'Есть сканер?'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'test_webhook_secret_123'])->assertOk();

        $this->assertCount(1, $this->alerts());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555');
    }

    public function test_ai_check_command_shows_the_reason(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota']], 429),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('ai:check')
            ->expectsOutputToContain('API key: set')
            ->expectsOutputToContain('You exceeded your current quota')
            ->expectsOutputToContain('Quota or rate limit reached')
            ->doesntExpectOutputToContain(self::KEY)
            ->assertFailed();
    }

    public function test_ai_check_command_succeeds_when_gemini_works(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]])]);

        $this->artisan('ai:check')->expectsOutputToContain('OK: the AI assistant answers.')->assertSuccessful();
    }
}
