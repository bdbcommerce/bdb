<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: edge cases of the "Waiting for reply" list. */
class AwaitingReplyQaTest extends TestCase
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
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'We sell RFID tags.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
                ])]]]]],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        TelegramBusinessConnection::create(['connection_id' => 'conn-1', 'user_id' => '777', 'name' => 'Sales 1']);
        TelegramBusinessConnection::create(['connection_id' => 'conn-2', 'user_id' => '888', 'name' => 'Sales 2']);
    }

    private function webhook(array $update): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++] + $update,
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function business(array $content, bool $fromOwner = false, string $conn = 'conn-1', int $chatId = 555, ?int $messageId = null, bool $edited = false): int
    {
        $messageId ??= 1000 + $this->updateId;
        $owner = $conn === 'conn-1' ? 777 : 888;
        $this->webhook([($edited ? 'edited_business_message' : 'business_message') => [
            'business_connection_id' => $conn,
            'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => 'Aziz'],
            'from' => ['id' => $fromOwner ? $owner : $chatId, 'first_name' => $fromOwner ? 'BDB' : 'Aziz'],
            'date' => time(),
        ] + $content]);

        return $messageId;
    }

    private function bot(array $content, int $chatId = 555, int $messageId = 1): void
    {
        $this->webhook(['message' => [
            'message_id' => $messageId,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'first_name' => 'Aziz', 'language_code' => 'ru'],
            'date' => time(),
        ] + $content]);
    }

    private function registered(): TelegramUser
    {
        return TelegramUser::create([
            'telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);
    }

    public function test_ai_reply_clears_in_replies_mode_and_media_still_waits(): void
    {
        config(['services.telegram.replies' => true]);
        $this->registered();

        $this->bot(['text' => 'Do you sell RFID?']);
        $this->assertSame(0, ChatConversation::awaitingReply()->count(), 'the AI answered');

        $this->bot(['photo' => [['file_id' => 'AgACAgIAAxkBAAIB_photo123', 'file_unique_id' => 'u1', 'width' => 1, 'height' => 1]]], messageId: 2);
        $this->assertSame(1, ChatConversation::awaitingReply()->count(), 'the bot does not answer photos');
    }

    public function test_registration_steps_are_not_waiting_in_replies_mode(): void
    {
        config(['services.telegram.replies' => true]);

        $this->bot(['text' => '/start']);
        $this->bot(['text' => 'Aziz'], messageId: 2);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());
    }

    public function test_media_from_business_customer_waits(): void
    {
        $this->business(['voice' => ['file_id' => 'AwACAgIAAxkBAAIB_voice123', 'file_unique_id' => 'v', 'duration' => 3]]);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.awaiting-replies.index'))->assertOk()->assertSee('[Voice]');
    }

    public function test_two_business_connections_with_the_same_chat_id_are_separate(): void
    {
        $this->business(['text' => 'To sales 1'], conn: 'conn-1');
        $this->business(['text' => 'To sales 2'], conn: 'conn-2');
        $this->assertSame(2, ChatConversation::awaitingReply()->count());

        $this->business(['text' => 'Answer from sales 2'], fromOwner: true, conn: 'conn-2');
        $this->assertSame(['conn-1'], ChatConversation::awaitingReply()->pluck('business_connection_id')->all());
    }

    public function test_bot_and_business_chat_with_same_id_do_not_supersede_each_other(): void
    {
        $this->business(['text' => 'Business question']);
        $this->bot(['text' => 'Bot question']);
        $this->assertSame(2, ChatConversation::awaitingReply()->count());
    }

    public function test_deleting_the_customer_message_clears_the_chat(): void
    {
        $this->business(['text' => 'Hi'], fromOwner: true);
        $id = $this->business(['text' => 'Oops, wrong chat']);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->webhook(['deleted_business_messages' => [
            'business_connection_id' => 'conn-1', 'chat' => ['id' => 555, 'type' => 'private'], 'message_ids' => [$id],
        ]]);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());
    }

    public function test_editing_an_old_session_message_does_not_bring_the_chat_back(): void
    {
        $old = $this->business(['text' => 'Old question']);
        $this->travel(25)->hours();
        $this->business(['text' => 'New session answer'], fromOwner: true);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        // The customer edits a message that lives in the previous session.
        $this->business(['text' => 'Old question (edited)'], messageId: $old, edited: true);
        $this->assertSame(0, ChatConversation::awaitingReply()->count(), 'an edit is not a new message');
    }

    public function test_bot_edit_of_unknown_message_after_mark_answered_does_not_resurrect(): void
    {
        $this->bot(['text' => 'Question']);
        $conversation = ChatConversation::sole();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('admin.chat-conversations.handled', $conversation))->assertRedirect();
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->webhook(['edited_message' => [
            'message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(), 'text' => 'Question (edited)',
        ]]);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());
    }

    public function test_bot_edit_of_an_old_session_message_does_not_bring_the_chat_back(): void
    {
        $this->bot(['text' => 'Old question'], messageId: 1);
        $this->travel(25)->hours();
        $this->bot(['text' => 'New question'], messageId: 2);
        $conversation = ChatConversation::latest('id')->first();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('admin.chat-conversations.reply', $conversation), ['message' => 'Answer'])->assertSessionHas('success');
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->webhook(['edited_message' => [
            'message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(), 'text' => 'Old question (edited)',
        ]]);
        $this->assertSame(0, ChatConversation::awaitingReply()->count(), 'an edit is not a new message');
    }

    public function test_access_control(): void
    {
        $this->business(['text' => 'Hi']);
        $conversation = ChatConversation::sole();

        $this->get(route('admin.awaiting-replies.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.chat-conversations.handled', $conversation))->assertRedirect(route('admin.login'));

        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('admin.awaiting-replies.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->post(route('admin.chat-conversations.handled', $conversation))->assertRedirect(route('admin.login'));
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('admin.chat-conversations.handled', 99999))->assertNotFound();
    }

    public function test_web_conversation_is_never_listed_and_mark_handled_is_harmless(): void
    {
        $web = ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'web', 'locale' => 'ru', 'messages' => [['role' => 'user', 'content' => 'Hi']], 'message_count' => 1,
        ]);
        $this->assertFalse((bool) $web->fresh()->awaiting_reply);
        $this->assertSame(0, ChatConversation::awaitingReply()->count());

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post(route('admin.chat-conversations.handled', $web))->assertRedirect();
    }

    public function test_long_cyrillic_and_script_titles_render_escaped(): void
    {
        $this->business(['text' => str_repeat('Ассалому алайкум, ўзбекча матн ', 200)]);
        ChatConversation::query()->update(['title' => '<script>alert(1)</script>'.str_repeat('Ш', 200)]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.awaiting-replies.index'))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false)
            ->assertSee('Ассалому алайкум');
    }

    public function test_backfill_migration_on_existing_rows(): void
    {
        $migration = require database_path('migrations/2026_11_07_000006_add_awaiting_reply_to_chat_conversations_table.php');
        $migration->down();

        $row = fn (string $channel, array $messages, ?string $ext = '1', ?string $conn = null) => DB::table('chat_conversations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'channel' => $channel, 'locale' => 'ru', 'external_id' => $ext, 'business_connection_id' => $conn,
            'messages' => json_encode($messages), 'message_count' => count($messages), 'unanswered_count' => 0,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);
        $waiting = $row('business', [['role' => 'owner', 'content' => 'Hi'], ['role' => 'user', 'content' => 'Price?', 'at' => '2026-11-01T09:30:00+00:00']], '1', 'conn-1');
        $answered = $row('business', [['role' => 'user', 'content' => 'Price?'], ['role' => 'owner', 'content' => '10$']], '2', 'conn-1');
        $botAside = $row('telegram', [['role' => 'user', 'content' => '/start', 'aside' => true]], '3');
        $noAt = $row('telegram', [['role' => 'user', 'content' => 'Q'], ['role' => 'assistant', 'content' => 'A'], ['role' => 'user', 'type' => 'photo', 'file_id' => 'x']], '4');
        $web = $row('web', [['role' => 'user', 'content' => 'Q']], null);
        $empty = $row('telegram', [], '5');

        $migration->up();

        $get = fn (int $id) => DB::table('chat_conversations')->find($id);
        $this->assertEquals(1, $get($waiting)->awaiting_reply);
        $this->assertStringStartsWith('2026-11-01 09:30:00', $get($waiting)->last_message_at);
        $this->assertSame('2026-11-01 10:00:00', $get($waiting)->updated_at, 'updated_at untouched');
        $this->assertEquals(0, $get($answered)->awaiting_reply);
        $this->assertEquals(0, $get($botAside)->awaiting_reply);
        $this->assertEquals(1, $get($noAt)->awaiting_reply);
        $this->assertNotNull($get($noAt)->last_message_at);
        $this->assertEquals(0, $get($web)->awaiting_reply);
        $this->assertEquals(0, $get($empty)->awaiting_reply);
        $this->assertNull($get($empty)->last_message_at);
        $this->assertSame(2, ChatConversation::awaitingReply()->count());
    }

    public function test_layout_badge_uses_a_constant_number_of_queries(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $count = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get(route('admin.awaiting-replies.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->business(['text' => 'One'], chatId: 1);
        $one = $count();
        foreach (range(2, 8) as $chat) {
            $this->business(['text' => 'Q'], chatId: $chat);
        }
        $this->assertSame($one, $count(), 'no N+1 on the list page');
    }
}
