<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Logic review of the "guest" step (bot replies on, registration optional): collisions with
 * ask_registration, the Business registration flow, /language and Waiting for reply.
 * Tests marked CONFLICT fail while the conflict is open.
 */
class TelegramGuestLogicReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    private int $messageId = 1;

    private int $sentId = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100123',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => true,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => 'We sell Zebra scanners.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true, 'topic' => 'scanners',
            ])]]]]]])
            : Http::response(['ok' => true, 'result' => ['message_id' => $this->sentId++]]));
    }

    private function send(array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => $this->messageId++,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'],
            'date' => time(),
        ] + $message], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function business(string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'business_message' => [
            'business_connection_id' => 'conn-1',
            'message_id' => 100 + $this->updateId,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'ru'],
            'date' => time(),
            'text' => $text,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function lastBotText(): string
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '555'
            && ! isset($r['business_connection_id']))->last()[0]['text'];
    }

    private function tickAskRegistration(TelegramUser $user): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', $user), ['ask_registration' => '1'])->assertSessionHasNoErrors();
    }

    private function guest(array $extra = []): TelegramUser
    {
        return TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST] + $extra);
    }

    /** CONFLICT 1: the admin's "ask for registration info" is silently ignored in the bot chat when replies are on. */
    public function test_ask_registration_ticked_for_a_guest_makes_the_bot_ask(): void
    {
        $user = $this->guest();
        $this->tickAskRegistration($user);
        $this->assertTrue($user->fresh()->ask_registration);

        $this->send(['text' => 'Какие сканеры у вас есть?']);

        $this->assertSame(TelegramUser::STEP_NAME, $user->fresh()->step, 'the question is answered, then the name is asked');
        $this->assertStringContainsString(__('chat.tg_ask_name', [], 'ru'), $this->lastBotText(),
            'admin ticked "ask for registration info", but the guest only got an AI answer');
    }

    /** CONFLICT 2: a guest who already gave a name ("Not now" at the phone step) is forced into registration by /language. */
    public function test_language_switch_keeps_a_guest_with_a_name_a_guest(): void
    {
        $this->guest();
        $this->send(['text' => '📝 Регистрация']);
        $this->send(['text' => 'Aziz']);
        $this->send(['text' => '⏭ Не сейчас']);
        $this->assertSame([TelegramUser::STEP_GUEST, 'Aziz'], [TelegramUser::sole()->step, TelegramUser::sole()->name]);

        $this->send(['text' => '/language']);
        $this->send(['text' => '🇬🇧 English']);

        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step, 'actual: phone step, registration restarted');
    }

    /** CONFLICT 2b: a name typed by an admin on the user page sends a brand-new bot customer straight to the phone step. */
    public function test_admin_entered_name_does_not_force_registration_after_language_pick(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'step' => TelegramUser::STEP_LANGUAGE, 'name' => 'Aziz', 'source' => 'business']);

        $this->send(['text' => '/start']);
        $this->send(['text' => '🇷🇺 Русский']);

        $this->assertSame(TelegramUser::STEP_GUEST, TelegramUser::sole()->step, 'actual: phone step instead of guest welcome');
    }

    /** CONFLICT 3: the Business registration flow moves the shared step to NAME; the bot then takes a guest's question as the name. */
    public function test_business_registration_step_does_not_swallow_a_bot_question(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'BDB Sales', 'can_reply' => true]);
        $user = $this->guest();
        $this->tickAskRegistration($user);

        $this->business('Здравствуйте');           // Recorder asks the name on behalf of the account
        $this->assertSame(TelegramUser::STEP_NAME, $user->fresh()->step);

        $this->send(['text' => 'Цены на сканеры']);   // a question in the bot chat (3 words, letters only)

        $this->assertNull($user->fresh()->name, 'actual: the bot-chat question became the customer name');
    }

    /** CONFLICT 4: a real question typed at the name step is neither answered nor listed in Waiting for reply. */
    public function test_question_typed_during_optional_registration_is_not_lost(): void
    {
        $this->guest();
        $this->send(['text' => '📝 Регистрация']);
        $this->send(['text' => 'Сколько стоит доставка сканеров в Самарканд?']);

        $conversation = ChatConversation::sole();
        $answered = collect($conversation->messages)->contains('role', 'assistant');
        $this->assertTrue($answered || $conversation->awaiting_reply,
            'actual: stored as aside, not answered, not waiting for reply — invisible to staff');
    }

    /** Works: record-only switch with a guest who has ask_registration — the language question comes first. */
    public function test_guest_switched_to_record_only_with_ask_registration_gets_the_language_question(): void
    {
        $user = $this->guest();
        $this->tickAskRegistration($user); // replies on: step is not reset
        config(['services.telegram.replies' => false]);

        $this->send(['text' => 'Salom']);

        $this->assertSame(TelegramBot::LANGUAGE_PROMPT ?? null, $this->lastBotText());
    }
}
