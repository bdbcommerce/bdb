<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Ask for registration info" in Premium-account (Telegram Business) chats, while the bot only records. */
class BusinessRegistrationAskTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const OWNER = 777;

    private int $updateId = 1;

    private int $sentId = 5000;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
        ]);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => $this->sentId++]]));
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => (string) self::OWNER, 'name' => 'BDB Sales', 'can_reply' => true]);
    }

    private function customer(array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'business_message' => $message + [
            'business_connection_id' => 'conn-1',
            'message_id' => $this->updateId,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'ru'],
            'date' => time(),
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<Request> */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0])->values()->all();
    }

    private function ask(): TelegramUser
    {
        $user = TelegramUser::sole();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.telegram-users.update', $user), ['ask_registration' => '1'])->assertSessionHasNoErrors();

        return $user->fresh();
    }

    public function test_full_flow_in_a_business_chat(): void
    {
        $this->customer(['text' => 'Narxlar qancha?']);
        $this->assertSame([], $this->sent()); // nothing until an admin asks
        $this->ask();

        $this->customer(['text' => 'Hello, are you there?']);
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(['conn-1', '555', __('chat.tg_biz_ask_name', [], 'ru')], [$sent[0]['business_connection_id'], (string) $sent[0]['chat_id'], $sent[0]['text']]);

        $this->customer(['text' => 'A']); // invalid name
        $this->assertSame(__('chat.tg_name_invalid', [], 'ru'), last($this->sent())['text']);
        $this->customer(['text' => 'Aziz Karimov']);
        $this->assertSame(__('chat.tg_biz_ask_phone', ['name' => 'Aziz Karimov'], 'ru'), last($this->sent())['text']);
        $this->customer(['text' => 'call me']); // not a phone
        $this->assertSame(__('chat.tg_biz_phone_invalid', [], 'ru'), last($this->sent())['text']);
        $this->customer(['text' => '90 123 45 67']);
        $this->assertSame(__('chat.tg_biz_registered', [], 'ru'), last($this->sent())['text']);

        $user = TelegramUser::sole();
        $this->assertTrue($user->isRegistered());
        $this->assertFalse($user->ask_registration);
        $this->assertSame(['Aziz Karimov', '+998901234567', 'ru'], [$user->name, $user->phone, $user->locale]);

        // Afterwards: recording only.
        $count = count($this->sent());
        $this->customer(['text' => 'Rahmat']);
        $this->assertCount($count, $this->sent());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));

        // The bot's questions are in the chat; the real questions still wait for the team.
        $conversation = ChatConversation::sole();
        $this->assertContains('bot', array_column($conversation->messages, 'role'));
        $this->assertTrue($conversation->awaiting_reply);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.awaiting-replies.index'))
            ->assertOk()->assertSee('Rahmat');
    }

    public function test_echo_of_the_bots_message_is_not_recorded_twice_and_does_not_answer(): void
    {
        $this->customer(['text' => 'Salom']);
        $this->ask();
        $this->customer(['text' => 'Price?']);
        $botMessageId = $this->sentId - 1;

        // Telegram echoes what the bot sent on behalf of the account.
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => $botMessageId,
            'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => self::OWNER, 'first_name' => 'BDB'],
            'sender_business_bot' => ['id' => 1, 'is_bot' => true, 'first_name' => 'BDB bot'],
            'date' => time(), 'text' => __('chat.tg_biz_ask_name', [], 'ru'),
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $conversation = ChatConversation::sole();
        $this->assertSame(1, collect($conversation->messages)->where('role', 'bot')->count());
        $this->assertTrue($conversation->awaiting_reply); // the bot's question is not the team's answer
    }

    public function test_admin_name_is_used_and_permission_is_needed(): void
    {
        $this->customer(['text' => 'Salom']);
        $user = $this->ask();
        $user->update(['name' => 'Aziz Karimov']);

        $this->customer(['text' => 'Hello']);
        $this->assertSame(__('chat.tg_biz_ask_phone', ['name' => 'Aziz Karimov'], 'ru'), last($this->sent())['text']);

        // Without the "reply" permission nothing is sent, and the admin is told.
        TelegramBusinessConnection::query()->update(['can_reply' => false]);
        $count = count($this->sent());
        $this->customer(['text' => '+998901234567']);
        $this->assertCount($count, $this->sent());
        $this->assertFalse($user->fresh()->isRegistered());
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.telegram-users.show', $user))
            ->assertOk()->assertSee('does not let the bot reply');
    }
}
