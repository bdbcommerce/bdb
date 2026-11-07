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

/** Telegram Business (Premium account) and group chats are recorded, never answered. */
class TelegramRecordingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private const SALES_CHAT = '-1001234567890';

    private const OWNER = 777;

    private int $updateId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => self::SALES_CHAT,
            'services.telegram.webhook_secret' => self::SECRET,
        ]);
        // Per-method answers ($this->results), else an empty ok.
        Http::fake(fn (Request $r) => Http::response(['ok' => true, 'result' => $this->results[basename($r->url())] ?? []]));
    }

    /** @var array<string, array> */
    private array $results = [];

    private function webhook(array $update)
    {
        return $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update,
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function connect(bool $enabled = true): void
    {
        $this->webhook(['business_connection' => [
            'id' => 'conn-1',
            'user' => ['id' => self::OWNER, 'first_name' => 'BDB', 'last_name' => 'Sales', 'username' => 'bdb_sales'],
            'user_chat_id' => self::OWNER,
            'date' => time(),
            'rights' => ['can_reply' => true],
            'is_enabled' => $enabled,
        ]]);
    }

    private function businessMessage(array $message, int $fromId = 555, string $key = 'business_message'): void
    {
        $this->webhook([$key => [
            'business_connection_id' => 'conn-1',
            'message_id' => $message['message_id'] ?? 1,
            'chat' => ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz', 'username' => 'aziz_uz'],
            'from' => $fromId === self::OWNER
                ? ['id' => self::OWNER, 'first_name' => 'BDB', 'last_name' => 'Sales']
                : ['id' => 555, 'first_name' => 'Aziz', 'language_code' => 'uz'],
            'date' => time(),
        ] + $message]);
    }

    private function groupMessage(array $message, int|string $chatId = -100500, string $key = 'message'): void
    {
        $this->webhook([$key => [
            'message_id' => $message['message_id'] ?? 1,
            'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Dealers chat'],
            'from' => ['id' => 42, 'first_name' => 'Bobur', 'last_name' => 'K.'],
            'date' => time(),
        ] + $message]);
    }

    private function sentMessages(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') || str_ends_with($r->url(), '/sendChatAction'))->count();
    }

    public function test_business_connection_is_saved_and_updated(): void
    {
        $this->connect();

        $connection = TelegramBusinessConnection::sole();
        $this->assertSame((string) self::OWNER, $connection->user_id);
        $this->assertSame('BDB Sales', $connection->label());
        $this->assertTrue($connection->is_enabled);
        $this->assertTrue($connection->can_reply);

        $this->connect(enabled: false);
        $this->assertFalse(TelegramBusinessConnection::sole()->is_enabled);
    }

    public function test_business_chat_is_recorded_with_customer_and_owner_and_not_answered(): void
    {
        $this->connect();

        $this->businessMessage(['message_id' => 10, 'text' => 'Do you have barcode scanners?']);
        $this->businessMessage(['message_id' => 11, 'text' => 'Yes, several models.'], self::OWNER);
        $this->businessMessage(['message_id' => 12, 'photo' => [['file_id' => 'small_photo_123'], ['file_id' => 'large_photo_456']], 'caption' => 'This one?']);

        $conversation = ChatConversation::sole();
        $this->assertSame('business', $conversation->channel);
        $this->assertSame('555', $conversation->external_id);
        $this->assertSame('conn-1', $conversation->business_connection_id);
        $this->assertSame('Aziz', $conversation->title);
        $this->assertSame('uz', $conversation->locale);
        $this->assertSame(3, $conversation->message_count);

        [$q, $a, $photo] = $conversation->messages;
        $this->assertSame(['user', 'Aziz', 'Do you have barcode scanners?'], [$q['role'], $q['sender'], $q['content']]);
        $this->assertSame(['owner', 'BDB Sales'], [$a['role'], $a['sender']]);
        $this->assertSame(['photo', 'large_photo_456', 'This one?'], [$photo['type'], $photo['file_id'], $photo['caption']]);

        $this->assertSame(0, $this->sentMessages());
        $this->assertSame(0, TelegramUser::count());
    }

    public function test_business_edits_and_deletions_are_recorded(): void
    {
        $this->connect();
        $this->businessMessage(['message_id' => 10, 'text' => 'Price for 100 labels?']);
        $this->businessMessage(['message_id' => 10, 'text' => 'Price for 1000 labels?'], key: 'edited_business_message');

        $this->webhook(['deleted_business_messages' => [
            'business_connection_id' => 'conn-1',
            'chat' => ['id' => 555, 'type' => 'private'],
            'message_ids' => [10],
        ]]);

        $messages = ChatConversation::sole()->messages;
        $this->assertCount(1, $messages);
        $this->assertSame('Price for 1000 labels?', $messages[0]['content']);
        $this->assertTrue($messages[0]['edited']);
        $this->assertTrue($messages[0]['deleted']);
    }

    public function test_unknown_business_connection_is_fetched_from_telegram(): void
    {
        $this->results['getBusinessConnection'] = [
            'id' => 'conn-1', 'user' => ['id' => self::OWNER, 'first_name' => 'BDB'], 'user_chat_id' => self::OWNER, 'is_enabled' => true,
        ];

        $this->businessMessage(['text' => 'Hello'], self::OWNER);

        $this->assertSame('BDB', TelegramBusinessConnection::sole()->name);
        $this->assertSame('owner', ChatConversation::sole()->messages[0]['role']);
    }

    public function test_group_messages_are_recorded_without_replies(): void
    {
        $this->groupMessage(['message_id' => 1, 'text' => 'Who sells Zebra printers?']);
        $this->groupMessage(['message_id' => 2, 'voice' => ['file_id' => 'voice_file_789']]);
        $this->groupMessage(['message_id' => 3, 'new_chat_members' => [['id' => 9]]]); // service message, recorded by type
        $this->groupMessage(['message_id' => 1, 'text' => 'Who sells Zebra printers in Tashkent?'], key: 'edited_message');

        $conversation = ChatConversation::sole();
        $this->assertSame('group', $conversation->channel);
        $this->assertSame('Dealers chat', $conversation->title);
        $this->assertSame(3, $conversation->message_count);
        $this->assertSame(['new_chat_members', null], [$conversation->messages[2]['type'], $conversation->messages[2]['file_id']]); // service messages by type
        $this->assertSame(['member', 'Bobur K.', 'Who sells Zebra printers in Tashkent?'],
            [$conversation->messages[0]['role'], $conversation->messages[0]['sender'], $conversation->messages[0]['content']]);
        $this->assertSame('voice', $conversation->messages[1]['type']);

        $this->assertSame(0, $this->sentMessages());
    }

    public function test_sales_and_alert_chats_are_not_recorded(): void
    {
        config(['services.telegram.alert_chat_id' => '-100999']);

        $this->groupMessage(['text' => 'Lead taken'], self::SALES_CHAT);
        $this->groupMessage(['text' => 'AI error seen'], '-100999');

        $this->assertSame(0, ChatConversation::count());
    }

    public function test_idle_group_chat_starts_a_new_conversation(): void
    {
        $this->groupMessage(['message_id' => 1, 'text' => 'First']);
        $this->travel(25)->hours();
        $this->groupMessage(['message_id' => 2, 'text' => 'Next day']);

        $this->assertSame(2, ChatConversation::where('channel', 'group')->count());
    }

    public function test_admin_sees_recorded_chats_and_setup_page(): void
    {
        $this->connect();
        $this->businessMessage(['text' => 'Need <b>RFID</b> tags']);
        $this->groupMessage(['text' => 'Group question']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.chat-conversations.index', ['channel' => 'business']))
            ->assertOk()->assertSee('Aziz')->assertSee('Need &lt;b&gt;RFID&lt;/b&gt; tags', false)->assertDontSee('Group question');

        $business = ChatConversation::where('channel', 'business')->sole();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $business))
            ->assertOk()->assertSee('Telegram Business')->assertSee('Recorded only')->assertDontSee('Message the customer in Telegram');

        $group = ChatConversation::where('channel', 'group')->sole();
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $group))
            ->assertOk()->assertSee('Dealers chat')->assertSee('Bobur K.');

        // Staff replies are only for the bot's own chats.
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $group), ['message' => 'Hi'])->assertNotFound();

        $this->actingAs($admin)->get(route('admin.telegram.edit'))
            ->assertOk()->assertSee('Telegram Business')->assertSee('BDB Sales')->assertSee('Dealers chat');
    }

    public function test_status_reports_missing_update_types(): void
    {
        $this->results = [
            'getMe' => ['username' => 'bdb_bot', 'can_connect_to_business' => false],
            'getWebhookInfo' => ['url' => 'https://bdb.uz/telegram/webhook', 'allowed_updates' => ['message']],
        ];
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.telegram.edit', ['check' => 1]))
            ->assertOk()->assertSee('press Connect webhook again')->assertSee('Business Mode');
    }

    // Bot private chat: every message is recorded -------------------------------------

    private function privateMessage(array $message, string $key = 'message'): void
    {
        $this->webhook([$key => [
            'message_id' => $message['message_id'] ?? 1,
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'],
            'date' => time(),
        ] + $message]);
    }

    public function test_bot_chat_records_registration_steps_without_feeding_them_to_the_ai(): void
    {
        $this->privateMessage(['message_id' => 1, 'text' => '/start']);
        $this->privateMessage(['message_id' => 2, 'text' => '🇬🇧 English']);
        $this->privateMessage(['message_id' => 3, 'text' => 'Aziz']);
        $this->privateMessage(['message_id' => 4, 'contact' => ['phone_number' => '998901234567', 'user_id' => 555]]);

        $conversation = ChatConversation::sole();
        $this->assertSame([1, 2, 3, 4], array_column($conversation->messages, 'message_id'));
        $this->assertSame(['/start', '🇬🇧 English', 'Aziz'], array_column(array_slice($conversation->messages, 0, 3), 'content'));
        $this->assertSame(['contact', null], [$conversation->messages[3]['type'], $conversation->messages[3]['file_id']]);
        $this->assertSame([], $conversation->textMessages());
        $this->assertTrue(TelegramUser::sole()->isRegistered());
    }

    public function test_answered_question_joins_the_dialogue_once(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'en', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        config(['services.gemini.key' => 'k']);
        Http::fake(fn (Request $r) => str_contains($r->url(), 'generativelanguage')
            ? Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'We have Zebra scanners.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true])]]]]]])
            : Http::response(['ok' => true, 'result' => []]));

        $this->privateMessage(['message_id' => 7, 'text' => 'Do you sell scanners?']);

        $messages = ChatConversation::sole()->messages;
        $this->assertCount(2, $messages);
        $this->assertSame(['user', 'Do you sell scanners?', 7], [$messages[0]['role'], $messages[0]['content'], $messages[0]['message_id']]);
        $this->assertArrayNotHasKey('aside', $messages[0]);
        $this->assertSame('assistant', $messages[1]['role']);
    }

    public function test_bot_chat_edits_update_the_recorded_message(): void
    {
        $this->privateMessage(['message_id' => 5, 'text' => 'Hello']);
        $this->privateMessage(['message_id' => 5, 'text' => 'Hello there'], 'edited_message');

        $messages = ChatConversation::sole()->messages;
        $this->assertCount(1, $messages);
        $this->assertSame('Hello there', $messages[0]['content']);
        $this->assertTrue($messages[0]['edited']);
    }

    public function test_unknown_message_kinds_are_recorded_by_type(): void
    {
        $this->groupMessage(['message_id' => 1, 'checklist' => ['title' => 'Order']]);
        $this->groupMessage(['message_id' => 2, 'paid_media' => ['star_count' => 5], 'caption' => 'Catalog']);
        $this->groupMessage(['message_id' => 3, 'video_note' => ['file_id' => 'vn_1']]);

        $messages = ChatConversation::sole()->messages;
        $this->assertSame(['checklist', 'paid_media', 'video_note'], array_column($messages, 'type'));
        $this->assertSame([null, null, 'vn_1'], array_column($messages, 'file_id'));
        $this->assertSame('Catalog', $messages[1]['caption']);
    }
}
