<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Waiting for reply": Telegram chats whose last message is the customer's. */
class AwaitingReplyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const OWNER = 777;

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.replies' => false,
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => (string) self::OWNER, 'name' => 'BDB Sales']);
    }

    private function webhook(array $update): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update,
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function business(string $text, bool $fromOwner = false, int $chatId = 555, string $name = 'Aziz'): void
    {
        $this->webhook(['business_message' => [
            'business_connection_id' => 'conn-1',
            'message_id' => $this->updateId,
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $name],
            'from' => ['id' => $fromOwner ? self::OWNER : $chatId, 'first_name' => $fromOwner ? 'BDB' : $name],
            'date' => time(),
            'text' => $text,
        ]]);
    }

    private function bot(string $text, int $chatId = 900): void
    {
        $this->webhook(['message' => [
            'message_id' => $this->updateId,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Dilnoza'],
            'date' => time(),
            'text' => $text,
        ]]);
    }

    public function test_customer_message_waits_until_the_owner_replies(): void
    {
        $this->business('Do you have RFID tags?');
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->business('Hello?');
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->business('Yes, we do.', fromOwner: true);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->business('Price?');
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
    }

    public function test_bot_chat_waits_until_staff_reply_and_groups_are_ignored(): void
    {
        $this->bot('Salom, narxlar?');
        $this->webhook(['message' => [
            'message_id' => 99, 'chat' => ['id' => -100500, 'type' => 'supergroup', 'title' => 'Dealers'],
            'from' => ['id' => 42, 'first_name' => 'B'], 'date' => time(), 'text' => 'Group question',
        ]]);

        $waiting = ChatConversation::awaitingReply()->get();
        $this->assertCount(1, $waiting);
        $this->assertSame('telegram', $waiting[0]->channel);

        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'Sardor']))
            ->post(route('admin.chat-conversations.reply', $waiting[0]), ['message' => 'Narxlar: ...'])
            ->assertSessionHas('success');
        $this->assertSame(0, ChatConversation::awaitingReply()->count());
    }

    public function test_mark_answered_until_the_customer_writes_again(): void
    {
        $this->business('Call me please');
        $conversation = ChatConversation::sole();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.chat-conversations.handled', $conversation))->assertRedirect();
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        // An edit does not bring it back; a new message does.
        $this->webhook(['edited_business_message' => [
            'business_connection_id' => 'conn-1', 'message_id' => $this->updateId - 1,
            'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Aziz'],
            'date' => time(), 'text' => 'Call me please!',
        ]]);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->business('Still waiting');
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
    }

    public function test_only_the_latest_session_counts_and_deleted_messages_are_ignored(): void
    {
        $this->business('Old question');
        $this->travel(25)->hours();
        $this->business('Answer in a new session', fromOwner: true);
        $this->assertSame(2, ChatConversation::count());
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->business('Another question', chatId: 556, name: 'Bobur');
        $this->business('Our answer', fromOwner: true, chatId: 556);
        $this->webhook(['deleted_business_messages' => [
            'business_connection_id' => 'conn-1', 'chat' => ['id' => 556, 'type' => 'private'], 'message_ids' => [$this->updateId - 1],
        ]]);
        $this->assertSame(1, ChatConversation::awaitingReply()->count()); // the owner's answer was deleted
    }

    public function test_page_menu_badge_and_dashboard(): void
    {
        $this->business('Need <b>labels</b>', name: 'Aziz');
        $this->bot('Bot question');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.awaiting-replies.index'))
            ->assertOk()
            ->assertSee('Waiting for reply')
            ->assertSee('Aziz')->assertSee('Need &lt;b&gt;labels&lt;/b&gt;', false)
            ->assertSee('Dilnoza')->assertSee('Bot question')
            ->assertSee('1 message without answer')
            ->assertSee('Mark answered')
            ->assertSee('chats waiting for a reply'); // menu badge (sr-only label)

        $this->actingAs($admin)->get(route('admin.awaiting-replies.index', ['channel' => 'business', 'sort' => 'newest']))
            ->assertOk()->assertSee('Aziz')->assertDontSee('Bot question');
        $this->actingAs($admin)->get(route('admin.awaiting-replies.index', ['channel' => ['x'], 'sort' => 'bad']))->assertOk();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Waiting for reply');
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', ChatConversation::where('channel', 'business')->sole()))
            ->assertOk()->assertSee('Mark as answered');

        auth()->logout();
        $this->get(route('admin.awaiting-replies.index'))->assertRedirect(route('admin.login'));
    }
}
