<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\CompanyLink;
use App\Models\InvoiceParserSetting;
use App\Models\TelegramChat;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Conversations → Telegram groups: list, roles, companies, members, recording on/off, leave. */
class TelegramGroupsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555', 'services.telegram.alert_chat_id' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function group(string $id = '-1007777', string $title = 'Dealers group'): TelegramChat
    {
        return TelegramChat::create(['chat_id' => $id, 'type' => 'supergroup', 'title' => $title, 'last_seen_at' => now()]);
    }

    private function groupPost(int $update, string $chatId, int $from, string $name, string $text): void
    {
        app(TelegramBot::class)->handle(['update_id' => $update, 'message' => ['message_id' => $update, 'date' => time(),
            'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'Dealers group'], 'from' => ['id' => $from, 'first_name' => $name], 'text' => $text]]);
    }

    public function test_list_and_page_show_roles_companies_and_members(): void
    {
        $group = $this->group();
        $sales = $this->group('-100555', 'Sales team');
        TelegramUser::create(['telegram_id' => '41', 'name' => 'Aziz Karimov', 'contact_type' => 'client']);
        $this->groupPost(1, '-1007777', 41, 'Aziz', 'Salom');
        $this->groupPost(2, '-1007777', 42, 'Bobur', 'Narx?');
        $this->groupPost(3, '-1007777', 41, 'Aziz', 'Rahmat');
        $client = Client::create(['name' => 'Demo LLC', 'inn' => '301234567']);
        CompanyLink::create(['client_id' => $client->id, 'group_chat_id' => '-1007777', 'linked_by' => 'manager', 'linked_by_name' => 'Ann']);

        $this->actingAs($this->admin)->get(route('admin.telegram-groups.index'))->assertOk()
            ->assertSee('Dealers group')->assertSee('Sales team')->assertSee('Sales chat')->assertSee('1 company')->assertSee('3 messages');
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $group))->assertOk()
            ->assertSee('Demo LLC')->assertSee('Aziz Karimov')->assertSee('2 messages')->assertSee('Bobur')->assertSee('no contact page');
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $sales))->assertOk()->assertSee('never recorded');

        $private = TelegramChat::create(['chat_id' => '41', 'type' => 'private', 'title' => 'Aziz']);
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $private))->assertNotFound();
        $this->get(route('admin.telegram-groups.index'))->assertOk(); // still signed in as admin
        auth()->logout();
        $this->get(route('admin.telegram-groups.index'))->assertRedirect();
    }

    public function test_recording_can_be_switched_off_and_on(): void
    {
        $group = $this->group();
        $this->actingAs($this->admin)->put(route('admin.telegram-groups.recording', $group), ['is_recorded' => 0])->assertRedirect();
        $this->groupPost(1, '-1007777', 41, 'Aziz', 'Salom');
        $this->assertSame(0, ChatConversation::where('channel', 'group')->count());

        $this->actingAs($this->admin)->put(route('admin.telegram-groups.recording', $group), ['is_recorded' => 1]);
        $this->groupPost(2, '-1007777', 41, 'Aziz', 'Yana');
        $this->assertSame(1, ChatConversation::where('channel', 'group')->count());
    }

    public function test_roles_are_set_with_the_invoice_settings_rules(): void
    {
        $group = $this->group();
        $other = $this->group('-1008888', 'Accountants');
        $sales = $this->group('-100555', 'Sales team');

        $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $group), ['role' => 'invoice_source', 'on' => 1])->assertSessionHasNoErrors();
        $this->assertSame('-1007777', InvoiceParserSetting::current()->source_chat_id);
        // The source cannot also be the invoice chat; the sales chat cannot be the invoice chat.
        $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $group), ['role' => 'invoice_chat', 'on' => 1])->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $sales), ['role' => 'invoice_chat', 'on' => 1])->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $other), ['role' => 'invoice_chat', 'on' => 1])->assertSessionHasNoErrors();
        $this->assertSame('-1008888', InvoiceParserSetting::current()->target_chat_id);

        $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $group), ['role' => 'invoice_source', 'on' => 0]);
        $this->assertNull(InvoiceParserSetting::current()->source_chat_id);
        $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $group), ['role' => 'json', 'on' => 1])->assertSessionHasErrors('role');
    }

    public function test_companies_are_linked_and_the_bot_leaves_only_a_plain_group(): void
    {
        $group = $this->group();
        $client = Client::create(['name' => 'Demo LLC']);
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.link', $group), ['client_id' => $client->id])->assertSessionHasNoErrors();
        $this->assertSame('-1007777', CompanyLink::sole()->group_chat_id);

        $sales = $this->group('-100555', 'Sales team');
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $sales))->assertSessionHasErrors('leave');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/leaveChat'));

        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $group))->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/leaveChat') && (string) $r['chat_id'] === '-1007777');
        $this->assertTrue($group->refresh()->isGone());
    }
}
