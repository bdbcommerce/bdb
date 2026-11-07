<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** TELEGRAM_BOT_REPLIES=false: the bot saves every private message and sends nothing. */
class TelegramRecordOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake();
    }

    private function send(array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => $message + [
            'message_id' => $this->updateId,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz', 'last_name' => 'Karimov', 'username' => 'aziz_uz'],
            'date' => time(),
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_messages_are_saved_and_nothing_is_sent(): void
    {
        $this->send(['text' => '/start']);
        $this->send(['text' => 'Printer narxi qancha?']);
        $this->send(['photo' => [['file_id' => 'small'], ['file_id' => 'big_photo_1']], 'caption' => 'Shu model']);
        for ($i = 0; $i < 20; $i++) {
            $this->send(['text' => 'spam '.$i]); // no "too fast" reply either
        }

        Http::assertNothingSent();
        $this->assertSame(0, ClientRequest::count());

        $user = TelegramUser::sole();
        $this->assertSame('Aziz Karimov', $user->displayName());
        $this->assertSame('aziz_uz', $user->username);
        $this->assertFalse($user->isRegistered());

        $messages = ChatConversation::sole()->messages;
        $this->assertCount(23, $messages);
        $this->assertSame(['user', '/start'], [$messages[0]['role'], $messages[0]['content']]);
        $this->assertArrayNotHasKey('aside', $messages[1]); // plain saved message, not a pending bot step
        $this->assertSame(['photo', 'big_photo_1', 'Shu model'], [$messages[2]['type'], $messages[2]['file_id'], $messages[2]['caption']]);
    }

    public function test_admin_sees_profile_name_and_mode(): void
    {
        $this->send(['text' => 'Salom']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.telegram-users.index'))
            ->assertOk()->assertSee('Aziz Karimov')->assertSee('Not registered')->assertDontSee('Step: language');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['q' => 'karim']))->assertOk()->assertSee('Aziz Karimov');
        $this->actingAs($admin)->get(route('admin.telegram.edit'))->assertOk()->assertSee('Mode: Record only');

        $conversation = ChatConversation::sole();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $conversation))
            ->assertOk()->assertSee('Salom')->assertDontSee('Bot step — not sent to the AI')
            ->assertSee('Message the customer in Telegram'); // staff can still reply
    }
}
