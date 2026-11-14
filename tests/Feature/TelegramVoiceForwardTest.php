<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Default voice mode: a customer's voice / audio is forwarded to the sales chat and the bot stays silent. */
class TelegramVoiceForwardTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const SALES = '-100555000';

    private int $updateId = 1;

    private bool $forwardFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => self::SALES,
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
            'services.groq.key' => 'gsk_should_not_be_used',
            'app.url' => 'https://bdb-commerce.uz',
        ]);
        Http::fake(fn (Request $r) => $this->forwardFails && str_ends_with($r->url(), '/forwardMessage')
            ? Http::response(['ok' => false, 'description' => 'Bad Request'], 400)
            : Http::response(['ok' => true, 'result' => ['message_id' => $this->forwardFails ? 88 : 77]]));
    }

    private function voice(int $messageId = 10, string $key = 'voice', int $chatId = 555): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Aziz', 'username' => 'aziz_uz'],
            'date' => time(),
            $key => ['file_id' => 'v1', 'duration' => 14, 'mime_type' => 'audio/ogg'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function calls(string $method): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/'.$method))->map(fn ($p) => $p[0])->values()->all();
    }

    public function test_voice_is_forwarded_to_the_sales_chat_with_the_customer_details(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'username' => 'aziz_uz', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz <b>', 'phone' => '+998901234567', 'registered_at' => now()]);

        $this->voice();

        $forward = $this->calls('forwardMessage')[0];
        $this->assertSame([self::SALES, '555', 10], [(string) $forward['chat_id'], (string) $forward['from_chat_id'], $forward['message_id']]);

        $sent = $this->calls('sendMessage');
        $this->assertCount(1, $sent, 'nothing is sent to the customer');
        $note = $sent[0];
        $this->assertSame(self::SALES, (string) $note['chat_id']);
        $this->assertSame('HTML', $note['parse_mode']);
        $this->assertSame(77, $note['reply_parameters']['message_id']);
        $this->assertStringContainsString('(14 s)', $note['text']);
        $this->assertStringContainsString('Aziz &lt;b&gt;', $note['text']);
        $this->assertStringContainsString('+998901234567', $note['text']);
        $this->assertStringContainsString('@aziz_uz', $note['text']);
        $this->assertStringContainsString('https://bdb-commerce.uz/admin/telegram-users/', $note['text']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com') || str_contains($r->url(), 'generateContent') || str_contains($r->url(), 'sendChatAction'));

        // Recorded as a customer message that waits for a staff reply.
        $conversation = ChatConversation::sole();
        $this->assertSame(['voice', 'v1'], [$conversation->messages[0]['type'], $conversation->messages[0]['file_id']]);
        $this->assertTrue($conversation->awaiting_reply);
    }

    public function test_unregistered_and_record_only_customers_are_forwarded_too_and_registration_is_not_disturbed(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_NAME]);

        $this->voice();
        $this->assertCount(1, $this->calls('forwardMessage'));
        $this->assertStringContainsString('not registered', $this->calls('sendMessage')[0]['text']);
        $this->assertSame(TelegramUser::STEP_NAME, TelegramUser::sole()->step, 'no "invalid name" reply, step unchanged');

        config(['services.telegram.replies' => false]);
        $this->voice(11, 'audio');
        $this->assertCount(2, $this->calls('forwardMessage'));
    }

    public function test_copy_is_used_when_forwarding_fails(): void
    {
        $this->forwardFails = true;

        $this->voice();

        $this->assertCount(1, $this->calls('copyMessage'));
        $this->assertSame(88, $this->calls('sendMessage')[0]['reply_parameters']['message_id']);
    }

    public function test_at_most_twenty_forwards_an_hour_per_customer(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->voice($i);
        }

        $this->assertCount(20, $this->calls('forwardMessage'));
        $this->assertCount(22, ChatConversation::sole()->messages, 'every voice is still recorded');
    }

    public function test_group_voice_is_never_forwarded(): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => 3, 'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'Team'],
            'from' => ['id' => 9, 'first_name' => 'X'], 'date' => time(), 'voice' => ['file_id' => 'g', 'duration' => 3],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $this->assertSame([], $this->calls('forwardMessage'));
    }

    public function test_ai_settings_show_the_forward_mode(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.ai-models.index'))->assertOk()
            ->assertSee('forwarded to the sales chat')->assertSee('TELEGRAM_VOICE_MODE=transcribe');
    }
}
