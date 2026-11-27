<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: chat picker escaping, malformed my_chat_member, settings forms, early TelegramChat::see. */
class TelegramChatPickerQaTest extends TestCase
{
    use RefreshDatabase;

    private const EVIL = '<script>alert(1)</script>"\'&</option><img src=x onerror=alert(2)>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555', 'services.telegram.replies' => true]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_titles_and_names_are_escaped_in_options_and_alpine_data(): void
    {
        TelegramChat::create(['chat_id' => '-1004444', 'type' => 'supergroup', 'title' => self::EVIL, 'last_seen_at' => now()]);
        TelegramChat::create(['chat_id' => '888', 'type' => 'private', 'title' => self::EVIL, 'last_seen_at' => now()]);
        TelegramUser::create(['telegram_id' => '999', 'name' => self::EVIL, 'username' => 'a"b']);
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'group', 'external_id' => '-1007777', 'locale' => 'ru', 'page' => 'Telegram', 'title' => self::EVIL, 'messages' => []]);
        $admin = $this->admin();

        foreach ([route('admin.invoice-parser.edit'), route('admin.sales-assistant.edit'), route('admin.clients.show', Client::create(['name' => 'Demo LLC']))] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('</option><img', $html, $url);
        }

        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->getContent();
        // Each x-data attribute must stay inside its double quotes: no raw " ' < > from the data.
        preg_match_all('/<div x-data="(\{\s*v:.*?)"\s*>/s', $html, $m);
        $this->assertCount(4, $m[1], 'four pickers on the invoice settings page');
        foreach ($m[1] as $data) {
            $this->assertStringNotContainsString('<', $data);
            $this->assertStringNotContainsString('"', $data);
        }
        $this->assertStringContainsString('<script', $html);
    }

    public function test_malformed_my_chat_member_updates_do_not_error(): void
    {
        $bot = app(TelegramBot::class);
        $payloads = [
            'x', 1, [], ['chat' => 'x'], ['chat' => ['id' => ['a']]], ['chat' => ['id' => 'abc', 'type' => 'group']],
            ['chat' => ['id' => str_repeat('9', 25), 'type' => 'group']], ['chat' => ['id' => -1001, 'type' => ['g']]],
            ['chat' => ['id' => -1002, 'type' => 'group'], 'new_chat_member' => 'member'],
            ['chat' => ['id' => -1003, 'type' => 'group'], 'new_chat_member' => ['status' => ['x']]],
            ['chat' => ['id' => -1004, 'type' => str_repeat('t', 40), 'title' => str_repeat('Ж', 400), 'username' => ['x']], 'new_chat_member' => ['status' => str_repeat('s', 40)]],
            ['chat' => ['id' => 5, 'type' => 'private', 'first_name' => ['x'], 'last_name' => 'Ли'], 'new_chat_member' => ['status' => 'kicked']],
        ];
        foreach ($payloads as $i => $p) {
            $bot->handle(['update_id' => 100 + $i, 'my_chat_member' => $p]);
        }
        // Malformed chat objects as message chats too.
        $bot->handle(['update_id' => 200, 'message' => ['chat' => 'x']]);
        $bot->handle(['update_id' => 201, 'channel_post' => ['chat' => ['id' => -1009, 'type' => 'channel', 'title' => 'News'], 'message_id' => 1, 'date' => 1, 'text' => 'x']]);
        $bot->handle(['update_id' => 202, 'edited_channel_post' => 'x']);

        $this->assertSame(['-1002', '-1003', '-1004', '5', '-1009'], TelegramChat::orderBy('id')->pluck('chat_id')->all());
        $this->assertSame('member', TelegramChat::where('chat_id', '-1002')->value('bot_status'));
        $row = TelegramChat::where('chat_id', '-1004')->first();
        $this->assertSame([16, 255, 16, null], [mb_strlen($row->type), mb_strlen($row->title), mb_strlen($row->bot_status), $row->username]);
        $this->assertSame(['Ли', 'kicked'], [TelegramChat::where('chat_id', '5')->value('title'), TelegramChat::where('chat_id', '5')->value('bot_status')]);
        Http::assertNothingSent();
        $this->assertSame(0, ChatConversation::count());
    }

    public function test_seen_is_throttled_but_bot_status_always_written(): void
    {
        $bot = app(TelegramBot::class);
        $msg = fn ($id, $title) => ['update_id' => $id, 'message' => ['message_id' => $id, 'date' => 1, 'chat' => ['id' => -100555, 'type' => 'supergroup', 'title' => $title], 'from' => ['id' => 6], 'text' => 'hi']];
        $bot->handle($msg(1, 'Sales'));
        $bot->handle($msg(2, 'Sales renamed'));
        $this->assertSame('Sales', TelegramChat::where('chat_id', '-100555')->value('title')); // throttled
        $bot->handle(['update_id' => 3, 'my_chat_member' => ['chat' => ['id' => -100555, 'type' => 'supergroup', 'title' => 'Sales new'], 'new_chat_member' => ['status' => 'administrator']]]);
        $this->assertSame(['Sales new', 'administrator'], [TelegramChat::where('chat_id', '-100555')->value('title'), TelegramChat::where('chat_id', '-100555')->value('bot_status')]);
        $bot->handle($msg(4, 'x'));
        $this->assertSame('administrator', TelegramChat::where('chat_id', '-100555')->value('bot_status')); // message keeps it
    }

    public function test_existing_flows_still_work_after_the_early_see(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => '-1003333', 'json_chat_id' => '-1006666']);
        $bot = app(TelegramBot::class);
        // Invoice source photo still captured, not recorded as a conversation.
        $bot->handle(['update_id' => 1, 'message' => ['message_id' => 5, 'date' => 1, 'chat' => ['id' => -1003333, 'type' => 'supergroup', 'title' => 'In'], 'from' => ['id' => 8],
            'photo' => [['file_id' => 'F1', 'file_unique_id' => 'U1', 'width' => 10, 'height' => 10]]]]);
        $this->assertSame(1, InvoiceMessage::count());
        // Group chat still recorded.
        $bot->handle(['update_id' => 2, 'message' => ['message_id' => 6, 'date' => 1, 'chat' => ['id' => -1008888, 'type' => 'supergroup', 'title' => 'Dealers'], 'from' => ['id' => 9, 'first_name' => 'Ali'], 'text' => 'Salom']]);
        $this->assertSame(1, ChatConversation::where('channel', 'group')->where('external_id', '-1008888')->count());
        // Business messages are recorded but not listed as chats.
        $bot->handle(['update_id' => 3, 'business_message' => ['business_connection_id' => 'BC', 'message_id' => 7, 'date' => 1, 'chat' => ['id' => 4242, 'type' => 'private', 'first_name' => 'Kim'], 'from' => ['id' => 4242, 'first_name' => 'Kim'], 'text' => 'Narx?']]);
        $this->assertNull(TelegramChat::where('chat_id', '4242')->first());
        $this->assertEqualsCanonicalizing(['-1003333', '-1008888'], TelegramChat::pluck('chat_id')->all());
    }

    public function test_invoice_settings_old_input_and_lists(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        $admin = $this->admin();
        TelegramChat::create(['chat_id' => '-1004444', 'type' => 'supergroup', 'title' => 'Warehouse', 'last_seen_at' => now()]);
        $form = ['enabled' => '1', 'model' => 'claude-opus-5-5', 'source_chat_id' => '-1001111', 'target_chat_id' => '-1004444', 'json_chat_id' => '-1006666'];
        $this->actingAs($admin)->from(route('admin.invoice-parser.edit'))->put(route('admin.invoice-parser.update'), $form + ['source_from_ids' => '555, @bad'])
            ->assertRedirect(route('admin.invoice-parser.edit'))->assertSessionHasErrors('source_from_ids');
        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('name="source_from_ids" value="555, @bad"', $html);
        $this->assertStringContainsString("v: '555, @bad'", $html);
        $this->assertStringContainsString('name="target_chat_id" value="-1004444"', $html);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), $form + ['source_from_ids' => "555,777\n-100123"])->assertSessionHasNoErrors();
        $this->assertSame('555, 777, -100123', InvoiceParserSetting::current()->source_from_ids);
    }

    public function test_sales_assistant_employee_ids_newline_list_kept(): void
    {
        $admin = $this->admin();
        SalesAssistantSetting::current()->update(['employee_ids' => "555\n@boss"]);
        $html = $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<textarea id="employee_ids" name="employee_ids" rows="3"[^>]*maxlength="3000"[^>]*>555\n@boss<\/textarea>/', $html);
        $this->assertStringContainsString("sep: '\\n'", $html);
    }

    public function test_guests_and_non_admins_cannot_open_the_pages(): void
    {
        foreach ([route('admin.invoice-parser.edit'), route('admin.sales-assistant.edit')] as $url) {
            $this->get($url)->assertRedirect();
            $this->actingAs(User::factory()->create(['is_admin' => false]))->get($url)->assertRedirect();
            auth()->logout();
        }
    }
}
