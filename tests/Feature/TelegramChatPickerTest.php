<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanyLink;
use App\Models\InvoiceParserSetting;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Every chat the bot sees is listed; chat / group ID fields are chosen from that list. */
class TelegramChatPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    public function test_groups_and_private_chats_the_bot_sees_are_listed(): void
    {
        $bot = app(TelegramBot::class);
        // Added to a group: listed before anyone writes there.
        $bot->handle(['update_id' => 1, 'my_chat_member' => ['chat' => ['id' => -1001111, 'type' => 'supergroup', 'title' => 'Accountants'],
            'from' => ['id' => 5], 'date' => 1, 'old_chat_member' => ['status' => 'left'], 'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 123]]]]);
        // The sales chat is never recorded as a conversation, but it is listed.
        $bot->handle(['update_id' => 2, 'message' => ['message_id' => 1, 'date' => 1, 'chat' => ['id' => -100555, 'type' => 'supergroup', 'title' => 'Sales'], 'from' => ['id' => 6], 'text' => 'hi']]);
        // A private chat with the bot.
        $bot->handle(['update_id' => 3, 'message' => ['message_id' => 2, 'date' => 1, 'chat' => ['id' => 777, 'type' => 'private', 'first_name' => 'Aziz'], 'from' => ['id' => 777, 'first_name' => 'Aziz', 'username' => 'aziz'], 'text' => '/start']]);

        $groups = TelegramChat::options('groups');
        $this->assertSame('Accountants', $groups['-1001111']);
        $this->assertSame('Sales', $groups['-100555']);
        $this->assertArrayNotHasKey('777', $groups->all());
        $this->assertStringContainsString('Aziz', TelegramChat::options('chats')['777']);

        // Removed from the group: still listed, marked.
        $bot->handle(['update_id' => 4, 'my_chat_member' => ['chat' => ['id' => -1001111, 'type' => 'supergroup', 'title' => 'Accountants'],
            'from' => ['id' => 5], 'date' => 2, 'old_chat_member' => ['status' => 'administrator'], 'new_chat_member' => ['status' => 'left', 'user' => ['id' => 123]]]]);
        $this->assertSame('Accountants (bot removed)', TelegramChat::options('groups')['-1001111']);
    }

    public function test_the_invoice_source_chat_is_listed_too(): void
    {
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333']);
        app(TelegramBot::class)->handle(['update_id' => 9, 'message' => ['message_id' => 5, 'date' => 1, 'chat' => ['id' => -1003333, 'type' => 'supergroup', 'title' => 'Invoices in'], 'from' => ['id' => 8], 'text' => 'note']]);

        $this->assertSame('Invoices in', TelegramChat::options('groups')['-1003333']);
    }

    public function test_settings_pages_offer_the_lists(): void
    {
        TelegramChat::create(['chat_id' => '-1004444', 'type' => 'supergroup', 'title' => 'Warehouse group', 'last_seen_at' => now()]);
        TelegramChat::create(['chat_id' => '888', 'type' => 'private', 'title' => 'Dilshod', 'last_seen_at' => now()]);
        TelegramUser::create(['telegram_id' => '999', 'name' => 'Malika Karimova']);
        $admin = User::factory()->create(['is_admin' => true]);

        $invoice = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk();
        $invoice->assertSee('id="source_chat_id-pick"', false)->assertSee('Warehouse group')->assertSee('Dilshod')->assertSee('Malika Karimova');
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('id="employee_ids-pick"', false)->assertSee('Malika Karimova');
        $this->actingAs($admin)->get(route('admin.clients.show', Client::create(['name' => 'Demo LLC'])))->assertOk()->assertSee('Warehouse group');
    }

    public function test_a_group_from_the_list_can_be_linked_to_a_company(): void
    {
        TelegramChat::create(['chat_id' => '-1004444', 'type' => 'supergroup', 'title' => 'Warehouse group', 'last_seen_at' => now()]);
        $client = Client::create(['name' => 'Demo LLC']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.companies.link', $client), ['group_chat_id' => '-1004444'])->assertRedirect();
        $this->assertSame('Warehouse group', CompanyLink::sole()->groupTitle());
        $this->actingAs($admin)->post(route('admin.companies.link', $client), ['group_chat_id' => '-1009999'])->assertStatus(422);
    }
}
