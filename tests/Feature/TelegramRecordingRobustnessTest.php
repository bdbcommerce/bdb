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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Malformed / partial updates for the recording feature, and admin rendering edge cases. */
class TelegramRecordingRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-1001234567890',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function connection(): TelegramBusinessConnection
    {
        return TelegramBusinessConnection::fromTelegram(['id' => 'conn-1', 'user' => ['id' => 777, 'first_name' => 'BDB'], 'is_enabled' => true]);
    }

    private function outgoing(): int
    {
        return Http::recorded(fn (Request $r) => preg_match('#/(sendMessage|sendChatAction|sendPhoto)$#', $r->url()))->count();
    }

    /** Payloads that are structurally plausible but partial: must be ignored or stored, never throw or answer. */
    public static function partialPayloads(): array
    {
        $chat = ['id' => 555, 'type' => 'private'];

        return [
            'business message without chat' => [['business_message' => ['business_connection_id' => 'conn-1', 'text' => 'hi']]],
            'business message without from/message_id/date' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'text' => 'hi']]],
            'business message without connection id' => [['business_message' => ['chat' => $chat, 'text' => 'hi']]],
            'business connection without id' => [['business_connection' => ['user' => ['id' => 1]]]],
            'business connection without user' => [['business_connection' => ['id' => 'conn-2']]],
            'deleted without chat' => [['deleted_business_messages' => ['business_connection_id' => 'conn-1', 'message_ids' => [1]]]],
            'deleted with scalar ids' => [['deleted_business_messages' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'message_ids' => '1']]],
            'group without chat id' => [['message' => ['chat' => ['type' => 'group'], 'text' => 'hi']]],
            'group without from' => [['message' => ['chat' => ['id' => -5, 'type' => 'group'], 'text' => 'hi']]],
            'group empty photo array' => [['message' => ['chat' => ['id' => -5, 'type' => 'supergroup'], 'photo' => []]]],
            'group photo without file_id' => [['message' => ['chat' => ['id' => -5, 'type' => 'supergroup'], 'photo' => [['width' => 1]]]]],
            'group chat as string' => [['message' => ['chat' => 'oops', 'text' => 'hi']]],
            'channel_post' => [['channel_post' => ['chat' => ['id' => -9, 'type' => 'channel'], 'text' => 'news']]],
            'edited_channel_post' => [['edited_channel_post' => ['chat' => ['id' => -9, 'type' => 'channel'], 'text' => 'news']]],
            'unknown update type' => [['my_chat_member' => ['chat' => ['id' => -5, 'type' => 'group']]]],
            'private edited message' => [['edited_message' => ['message_id' => 1, 'chat' => $chat, 'from' => ['id' => 555], 'text' => 'edited']]],
            'empty update' => [[]],
            'sender_chat only' => [['message' => ['chat' => ['id' => -5, 'type' => 'supergroup'], 'sender_chat' => ['id' => -5, 'title' => 'Admins'], 'text' => 'anon']]],
        ];
    }

    #[DataProvider('partialPayloads')]
    public function test_partial_payloads_do_not_throw_or_answer(array $update): void
    {
        $this->connection();

        app(TelegramBot::class)->handle($update);
        $this->postJson('/telegram/webhook', ['update_id' => random_int(1, PHP_INT_MAX)] + $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $this->assertSame(0, $this->outgoing());
        $this->assertSame(0, TelegramUser::count());
    }

    /** Wrong types where arrays/strings are expected. */
    public static function malformedPayloads(): array
    {
        $chat = ['id' => 555, 'type' => 'private'];

        return [
            'business_connection is a string' => [['business_connection' => 'x']],
            'business_message is a string' => [['business_message' => 'x']],
            'business from is a string' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'from' => 'x', 'text' => 'hi']]],
            'business text is an array' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'text' => ['a']]]],
            'business chat id is an array' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => ['id' => [1]], 'text' => 'hi']]],
            'connection id is an array' => [['business_message' => ['business_connection_id' => ['x'], 'chat' => $chat, 'text' => 'hi']]],
            'deleted is a string' => [['deleted_business_messages' => 'x']],
            'message is a string' => [['message' => 'x']],
            'group from is a string' => [['message' => ['chat' => ['id' => -5, 'type' => 'group'], 'from' => 'x', 'text' => 'hi']]],
            'group photo is a string' => [['message' => ['chat' => ['id' => -5, 'type' => 'group'], 'photo' => 'x']]],
            'group caption is an array' => [['message' => ['chat' => ['id' => -5, 'type' => 'group'], 'voice' => ['file_id' => 'f'], 'caption' => ['x']]]],
            'group title is an array' => [['message' => ['chat' => ['id' => -5, 'type' => 'group', 'title' => ['x']], 'text' => 'hi']]],
            'edited_message is a string' => [['edited_message' => 'x']],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_payloads_webhook_returns_ok(array $update): void
    {
        $this->connection();

        app(TelegramBot::class)->handle($update); // must not throw
        $this->postJson('/telegram/webhook', ['update_id' => random_int(1, PHP_INT_MAX)] + $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $this->assertSame(0, $this->outgoing());
        $this->assertSame(0, TelegramUser::count());
    }

    public function test_edit_without_message_id_never_overwrites_another_message(): void
    {
        $bot = app(TelegramBot::class);
        $chat = ['id' => -5, 'type' => 'supergroup', 'title' => 'G'];

        $bot->handle(['message' => ['chat' => $chat, 'from' => ['id' => 1, 'first_name' => 'A'], 'text' => 'first (no id)']]);
        $bot->handle(['edited_message' => ['chat' => $chat, 'from' => ['id' => 1, 'first_name' => 'A'], 'text' => 'edit (no id)']]);

        $messages = ChatConversation::sole()->messages;
        $this->assertSame(['first (no id)', 'edit (no id)'], array_column($messages, 'content'));
        $this->assertTrue($messages[1]['edited']);
    }

    public function test_long_and_unicode_titles_and_text_are_stored(): void
    {
        $bot = app(TelegramBot::class);
        $title = str_repeat('Гуруҳ oʻzbek ', 40); // > 255 chars
        $bot->handle(['message' => ['message_id' => 1, 'chat' => ['id' => -5, 'type' => 'supergroup', 'title' => $title],
            'from' => ['id' => 1, 'first_name' => 'Ўткир', 'language_code' => 'uz'], 'text' => str_repeat('я', 5000), 'date' => time()]]);

        $c = ChatConversation::sole();
        $this->assertSame(255, mb_strlen($c->title));
        $this->assertSame(4000, mb_strlen($c->messages[0]['content']));
        $this->assertSame('Ўткир', $c->messages[0]['sender']);
    }

    public function test_private_chat_still_answered_and_not_recorded_as_group(): void
    {
        app(TelegramBot::class)->handle(['message' => ['message_id' => 1, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Aziz'], 'text' => '/start']]);

        $this->assertSame(1, TelegramUser::count());
        $this->assertGreaterThan(0, $this->outgoing());
        $this->assertSame(0, ChatConversation::whereIn('channel', ['business', 'group'])->count());
    }

    public function test_deletion_in_other_connection_is_not_applied(): void
    {
        $this->connection();
        TelegramBusinessConnection::fromTelegram(['id' => 'conn-2', 'user' => ['id' => 888]]);
        $bot = app(TelegramBot::class);
        $chat = ['id' => 555, 'type' => 'private', 'first_name' => 'Aziz'];
        $bot->handle(['business_message' => ['business_connection_id' => 'conn-1', 'message_id' => 10, 'chat' => $chat, 'from' => ['id' => 555], 'text' => 'hi', 'date' => time()]]);
        $bot->handle(['deleted_business_messages' => ['business_connection_id' => 'conn-2', 'chat' => $chat, 'message_ids' => [10]]]);

        $this->assertArrayNotHasKey('deleted', ChatConversation::sole()->messages[0]);
    }

    public function test_admin_views_render_old_and_new_conversations_with_escaping(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Old bot conversation: no sender/at keys, a media item without caption.
        $old = ChatConversation::create([
            'uuid' => 'old-1', 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [
                ['role' => 'user', 'content' => 'Old question'],
                ['role' => 'assistant', 'content' => 'Old answer', 'answered' => true],
                ['role' => 'user', 'type' => 'photo', 'file_id' => 'AgACAgIAAxkBAAIB'],
                ['role' => 'admin', 'content' => 'Staff reply', 'author' => 'Ann'],
            ],
            'message_count' => 4,
        ]);
        // Old web conversation
        $web = ChatConversation::create(['uuid' => 'web-1', 'channel' => 'web', 'locale' => 'en', 'page' => '/en',
            'messages' => [['role' => 'user', 'content' => 'Web q'], ['role' => 'assistant', 'content' => 'Web a']], 'message_count' => 2]);

        $xss = '<script>alert(1)</script>';
        $new = ChatConversation::create([
            'uuid' => 'new-1', 'channel' => 'business', 'external_id' => '555', 'business_connection_id' => 'conn-1',
            'locale' => 'ru', 'page' => 'Account: '.$xss, 'title' => 'T'.$xss,
            'messages' => [
                ['role' => 'user', 'sender' => 'S'.$xss, 'message_id' => 1, 'at' => date('c'), 'content' => 'C'.$xss, 'edited' => true],
                ['role' => 'owner', 'sender' => 'Owner', 'message_id' => 2, 'at' => date('c'), 'type' => 'photo', 'file_id' => 'AgACAgIAAxkBAAIC', 'caption' => 'Cap'.$xss, 'deleted' => true],
                ['role' => 'owner', 'sender' => 'Owner', 'message_id' => 3, 'at' => date('c'), 'type' => 'location', 'file_id' => null],
                ['role' => 'member', 'sender' => 'M', 'message_id' => 4, 'at' => date('c'), 'content' => 'member text'],
            ],
            'message_count' => 4,
        ]);

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $old))->assertOk()
            ->assertSee('Old question')->assertSee('Staff reply')->assertSee('Message the customer in Telegram');
        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $web))->assertOk()->assertSee('Web q');

        $show = $this->actingAs($admin)->get(route('admin.chat-conversations.show', $new))->assertOk();
        $show->assertDontSee($xss, false)
            ->assertSee('Cap&lt;script&gt;', false)
            ->assertSee('Account owner')
            ->assertSee('edited')
            ->assertSee('deleted in Telegram')
            ->assertDontSee('Message the customer in Telegram');

        foreach ([null, 'web', 'telegram', 'business', 'group'] as $channel) {
            $this->actingAs($admin)->get(route('admin.chat-conversations.index', array_filter(['channel' => $channel])))
                ->assertOk()->assertDontSee($xss, false);
        }

        $this->actingAs($admin)->postJson(route('admin.chat-conversations.suggest', $new))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $new), ['message' => 'Hi'])->assertNotFound();
    }

    public function test_settings_page_escapes_group_titles_and_connection_names(): void
    {
        $xss = '<img src=x onerror=alert(1)>';
        TelegramBusinessConnection::fromTelegram(['id' => 'c', 'user' => ['id' => 1, 'first_name' => $xss, 'username' => 'u']]);
        ChatConversation::create(['uuid' => 'g', 'channel' => 'group', 'external_id' => '-5', 'locale' => 'ru', 'title' => $xss, 'messages' => [], 'message_count' => 3]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.telegram.edit'))
            ->assertOk()->assertDontSee($xss, false);
    }

    public function test_settings_and_conversation_pages_are_admin_only(): void
    {
        $this->get(route('admin.telegram.edit'))->assertRedirect();
        $this->get(route('admin.chat-conversations.index', ['channel' => 'business']))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.telegram.edit'))->assertRedirect(route('admin.login'));
    }
}
