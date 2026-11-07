<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramBusinessConnection;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Wrong-type fields (nested arrays, numeric strings, lists) in Business / group updates must never throw. */
class TelegramRecordingWrongTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-1001234567890',
            'services.gemini.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
        TelegramBusinessConnection::fromTelegram(['id' => 'conn-1', 'user' => ['id' => 777, 'first_name' => 'BDB']]);
    }

    public static function wrongTypes(): array
    {
        $chat = ['id' => 555, 'type' => 'private'];
        $group = ['id' => -5, 'type' => 'group'];

        return [
            'group: nested arrays in from' => [['message' => ['chat' => $group, 'from' => ['id' => [1], 'first_name' => ['x' => 'y'], 'username' => ['a']], 'text' => 'hi']]],
            'group: nested arrays in chat' => [['message' => ['chat' => ['id' => -5, 'type' => 'group', 'title' => ['a' => 'b']], 'text' => 'hi']]],
            'group: from is a string' => [['message' => ['chat' => $group, 'from' => 'bob', 'text' => 'hi']]],
            'group: from is a list' => [['message' => ['chat' => $group, 'from' => ['bob', 'smith'], 'text' => 'hi']]],
            'group: sender_chat nested' => [['message' => ['chat' => $group, 'sender_chat' => ['title' => ['x']], 'text' => 'hi']]],
            'group: sender_chat string' => [['message' => ['chat' => $group, 'sender_chat' => 'chan', 'text' => 'hi']]],
            'group: numeric strings' => [['message' => ['chat' => ['id' => '-5', 'type' => 'group'], 'message_id' => '12', 'date' => '1700000000', 'text' => 'hi']]],
            'group: photo as list of strings' => [['message' => ['chat' => $group, 'message_id' => 1, 'photo' => ['abc', 'def']]]],
            'group: photo as string' => [['message' => ['chat' => $group, 'message_id' => 1, 'photo' => 'abc']]],
            'group: photo empty list' => [['message' => ['chat' => $group, 'message_id' => 1, 'photo' => []]]],
            'group: document file_id array' => [['message' => ['chat' => $group, 'document' => ['file_id' => ['x']], 'caption' => ['c']]]],
            'group: text array' => [['message' => ['chat' => $group, 'text' => ['a', 'b']]]],
            'group: date non-numeric' => [['message' => ['chat' => $group, 'date' => 'yesterday', 'message_id' => 'abc', 'text' => 'hi']]],
            'group: edited, id string' => [['edited_message' => ['chat' => $group, 'message_id' => '1', 'text' => 'hi']]],
            'group: from language_code array' => [['message' => ['chat' => $group, 'from' => ['language_code' => ['ru']], 'text' => 'hi']]],
            'business: from nested' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'from' => ['id' => ['777']], 'text' => 'hi']]],
            'business: chat string' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => '555', 'text' => 'hi']]],
            'business: chat id nested' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => ['id' => [555]], 'text' => 'hi']]],
            'business: connection id numeric' => [['business_message' => ['business_connection_id' => 123, 'chat' => $chat, 'text' => 'hi']]],
            'business: connection id array' => [['business_message' => ['business_connection_id' => ['conn-1'], 'chat' => $chat, 'text' => 'hi']]],
            'business: photo list of strings' => [['business_message' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'photo' => ['a', 'b']]]],
            'business edit: message_id array' => [['edited_business_message' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'message_id' => [1], 'text' => 'x']]],
            'deleted: chat nested' => [['deleted_business_messages' => ['business_connection_id' => 'conn-1', 'chat' => ['id' => [1]], 'message_ids' => [1]]]],
            'deleted: ids nested / numeric strings' => [['deleted_business_messages' => ['business_connection_id' => 'conn-1', 'chat' => $chat, 'message_ids' => [[1], '2', 'x']]]],
            'deleted: chat string' => [['deleted_business_messages' => ['business_connection_id' => 'conn-1', 'chat' => 'x', 'message_ids' => [1]]]],
            'connection: user string' => [['business_connection' => ['id' => 'conn-3', 'user' => 'bob']]],
            'connection: rights string' => [['business_connection' => ['id' => 'conn-6', 'rights' => 'all']]],
            'business_message is a string' => [['business_message' => 'hi']],
            'message is a list' => [['message' => ['a', 'b']]],
            'connection id is an array' => [['business_connection' => ['id' => ['x']]]],
            'connection user id is an array' => [['business_connection' => ['id' => 'c', 'user' => ['id' => [1]]]]],
            'connection user_chat_id is an array' => [['business_connection' => ['id' => 'c', 'user_chat_id' => [1]]]],
            'private: username is an array' => [['message' => ['chat' => ['id' => 9, 'type' => 'private'], 'from' => ['username' => ['u']], 'text' => '/start']]],
            'private: text is an array' => [['message' => ['chat' => ['id' => 9, 'type' => 'private'], 'from' => ['id' => 9], 'text' => ['a']]]],
            'private: from string' => [['message' => ['chat' => ['id' => 9, 'type' => 'private'], 'from' => 'bob', 'text' => '/start']]],
        ];
    }

    #[DataProvider('wrongTypes')]
    public function test_wrong_types_do_not_throw(array $update): void
    {
        app(TelegramBot::class)->handle($update);

        $this->assertTrue(true);
    }

    public function test_numeric_string_ids_are_normalised_and_edit_matches(): void
    {
        $bot = app(TelegramBot::class);
        $bot->handle(['message' => ['chat' => ['id' => '-5', 'type' => 'group'], 'message_id' => '12', 'from' => ['first_name' => 'Ali'], 'text' => 'first']]);
        $bot->handle(['edited_message' => ['chat' => ['id' => -5, 'type' => 'group'], 'message_id' => 12, 'text' => 'changed']]);

        $c = ChatConversation::where('channel', 'group')->sole();
        $this->assertCount(1, $c->messages);
        $this->assertSame('changed', $c->messages[0]['content']);
        $this->assertSame(12, $c->messages[0]['message_id']);
    }

    private function biz(array $m, string $key = 'business_message'): void
    {
        app(TelegramBot::class)->handle([$key => ['business_connection_id' => 'conn-1', 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555]] + $m]);
    }

    public function test_edit_does_not_touch_updated_at_and_does_not_reopen_session(): void
    {
        $this->biz(['message_id' => 1, 'text' => 'old']);
        $c = ChatConversation::sole();
        $c->timestamps = false;
        $c->updated_at = now()->subHours(30);
        $c->save();
        $before = $c->fresh()->updated_at->toDateTimeString();

        $this->biz(['message_id' => 1, 'text' => 'new'], 'edited_business_message');
        $this->assertSame($before, ChatConversation::sole()->updated_at->toDateTimeString());
        $this->assertSame('new', ChatConversation::sole()->messages[0]['content']);

        $this->biz(['message_id' => 2, 'text' => 'next day']);
        $this->assertSame(2, ChatConversation::count(), 'a new message after 24h idle starts a new session');
    }

    public function test_deletion_is_applied_in_older_session(): void
    {
        $this->biz(['message_id' => 1, 'text' => 'first session']);
        $old = ChatConversation::sole();
        $old->timestamps = false;
        $old->updated_at = now()->subHours(30);
        $old->save();
        $this->biz(['message_id' => 2, 'text' => 'second session']);
        $this->assertSame(2, ChatConversation::count());

        app(TelegramBot::class)->handle(['deleted_business_messages' => ['business_connection_id' => 'conn-1', 'chat' => ['id' => 555], 'message_ids' => [1]]]);

        $this->assertTrue($old->fresh()->messages[0]['deleted'] ?? false);
        $this->assertArrayNotHasKey('deleted', ChatConversation::latest('id')->first()->messages[0]);
    }

    public function test_unknown_connection_lookup_is_throttled(): void
    {
        $bot = app(TelegramBot::class);
        foreach ([1, 2, 3] as $i) {
            $bot->handle(['business_message' => ['business_connection_id' => 'unknown', 'chat' => ['id' => 1], 'message_id' => $i, 'text' => 'x']]);
        }

        $this->assertSame(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/getBusinessConnection'))->count());
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_empty_owner_id_does_not_mark_sender_without_id_as_owner(): void
    {
        TelegramBusinessConnection::fromTelegram(['id' => 'conn-x']); // no user
        app(TelegramBot::class)->handle(['business_message' => ['business_connection_id' => 'conn-x', 'chat' => ['id' => 9], 'message_id' => 1, 'text' => 'hi']]);

        $this->assertSame('user', ChatConversation::sole()->messages[0]['role']);
    }

    public function test_settings_page_shows_latest_group_title_once(): void
    {
        $bot = app(TelegramBot::class);
        $bot->handle(['message' => ['chat' => ['id' => -7, 'type' => 'group', 'title' => 'Old name'], 'message_id' => 1, 'text' => 'a']]);
        $c = ChatConversation::sole();
        $c->timestamps = false;
        $c->updated_at = now()->subHours(30);
        $c->save();
        $bot->handle(['message' => ['chat' => ['id' => -7, 'type' => 'group', 'title' => 'New name'], 'message_id' => 2, 'text' => 'b']]);
        $this->assertSame(2, ChatConversation::count());

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.telegram.edit'))->assertOk()->assertSee('New name')->assertDontSee('Old name');
    }

    public function test_client_request_tabs_are_website_and_telegram_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.client-requests.index'))->assertOk()->getContent();
        $this->assertStringContainsString('channel=web', $html);
        $this->assertStringContainsString('channel=telegram', $html);
        $this->assertStringNotContainsString('channel=business', $html);
        $this->assertStringNotContainsString('channel=group', $html);

        $this->actingAs($admin)->get(route('admin.client-requests.index', ['channel' => 'group']))->assertOk();
        $this->actingAs($admin)->get(route('admin.client-requests.index', ['channel' => ['x']]))->assertOk();
    }

    public function test_photo_list_of_strings_is_stored_without_file_id(): void
    {
        app(TelegramBot::class)->handle(['message' => ['chat' => ['id' => -5, 'type' => 'group'], 'message_id' => 1, 'photo' => ['abc']]]);

        $m = ChatConversation::where('channel', 'group')->sole()->messages[0];
        $this->assertSame('photo', $m['type']);
        $this->assertNull($m['file_id']);
    }
}
