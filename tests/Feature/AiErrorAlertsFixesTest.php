<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\User;
use App\Services\AiErrorAlerts;
use App\Services\Telegram\TelegramApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * QA re-verification of the AI error alert fixes: secret masking in logs, the short
 * Telegram timeout for alerts, mute release on a failed send, and the Active checkbox default.
 */
class AiErrorAlertsFixesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyQAKEY1111111111111';

    private const BOT = '654321:QA-FIX-BOT-TOKEN';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => self::KEY,
            'services.telegram.bot_token' => self::BOT,
            'services.telegram.chat_id' => '-100555',
            'services.telegram.alert_chat_id' => '777',
        ]);
    }

    private function ask()
    {
        return $this->postJson('/chat/message', [
            'locale' => 'en', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);
    }

    public function test_gemini_error_body_in_log_has_secrets_masked(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => [
                'status' => 'INVALID_ARGUMENT', 'message' => 'bad key '.self::KEY.' and '.self::BOT,
            ]], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertOk();

        $all = implode("\n", $logged);
        $this->assertStringContainsString('Gemini request failed', $all);
        $this->assertStringNotContainsString(self::KEY, $all);
        $this->assertStringNotContainsString(self::BOT, $all);
    }

    public function test_alerts_use_a_three_second_timeout_and_release_the_mute_on_failure(): void
    {
        $api = Mockery::mock(TelegramApi::class);
        $api->shouldReceive('configured')->andReturn(true);
        $api->shouldReceive('sendMessage')->once()
            ->with('777', Mockery::type('string'), Mockery::type('array'), 3)
            ->andReturn(false);

        (new AiErrorAlerts($api))->report('QA problem', 'details');

        $this->assertFalse(Cache::has('ai-alert:'.md5('QA problem')), 'mute released after a failed send');
    }

    public function test_alert_send_exception_releases_the_mute(): void
    {
        $api = Mockery::mock(TelegramApi::class);
        $api->shouldReceive('configured')->andReturn(true);
        $api->shouldReceive('sendMessage')->twice()->andThrow(new \RuntimeException('boom'));

        $alerts = new AiErrorAlerts($api);
        $alerts->report('QA throw');
        $alerts->report('QA throw');

        $this->assertFalse(Cache::has('ai-alert:'.md5('QA throw')));
    }

    public function test_other_telegram_calls_keep_the_default_timeout(): void
    {
        $api = Mockery::mock(TelegramApi::class)->makePartial();
        $api->shouldReceive('configured')->andReturn(true);
        $api->shouldReceive('call')->once()
            ->with('sendMessage', Mockery::type('array'), 10)
            ->andReturn([]);

        $this->assertTrue($api->sendMessage('1', 'hi'));
    }

    public function test_new_form_has_active_checked_by_default_and_edit_keeps_inactive(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.ai-instructions.create'))->getContent();
        $this->assertMatchesRegularExpression('/name="is_active" value="1" checked/', $html);

        $doc = AiInstruction::create(['title' => 'T', 'kind' => 'rules', 'channel' => 'all', 'content' => 'c', 'is_active' => false]);
        $html = $this->actingAs($admin)->get(route('admin.ai-instructions.edit', $doc))->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="is_active" value="1" checked/', $html);
    }
}
