<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\CompanyLink;
use App\Models\InvoiceMessage;
use App\Models\InvoiceParserSetting;
use App\Models\TelegramChat;
use App\Models\User;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: Conversations → Telegram groups — access, escaping, role rules, members robustness, muting, leave, migration. */
class TelegramGroupsQaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555', 'services.telegram.alert_chat_id' => '-100666']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function group(string $id = '-1007777', string $title = 'Dealers group', array $extra = []): TelegramChat
    {
        return TelegramChat::create(['chat_id' => $id, 'type' => 'supergroup', 'title' => $title, 'last_seen_at' => now()] + $extra);
    }

    private function groupPost(int $update, string $chatId, string $text): void
    {
        app(TelegramBot::class)->handle(['update_id' => $update, 'message' => ['message_id' => $update, 'date' => time(),
            'chat' => ['id' => (int) $chatId, 'type' => 'supergroup', 'title' => 'G'], 'from' => ['id' => 41, 'first_name' => 'Aziz'], 'text' => $text]]);
    }

    public function test_guests_and_non_admins_are_kept_out_of_every_route(): void
    {
        $group = $this->group();
        $user = User::factory()->create(['is_admin' => false]);
        $routes = [['get', 'admin.telegram-groups.index'], ['get', 'admin.telegram-groups.show'], ['put', 'admin.telegram-groups.recording'],
            ['put', 'admin.telegram-groups.role'], ['post', 'admin.telegram-groups.link'], ['post', 'admin.telegram-groups.leave']];
        foreach ($routes as [$method, $name]) {
            $url = $name === 'admin.telegram-groups.index' ? route($name) : route($name, $group);
            $this->$method($url)->assertRedirect();
            $r = $this->actingAs($user)->$method($url);
            $this->assertTrue(in_array($r->status(), [302, 403], true), "$name non-admin got {$r->status()}");
            auth()->logout();
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/leaveChat'));
        $this->assertTrue($group->refresh()->is_recorded);
        // Wrong method.
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $group).'/leave')->assertStatus(405);
    }

    public function test_titles_senders_and_company_names_are_escaped(): void
    {
        $xss = '<script>alert(1)</script>';
        $group = $this->group('-1007777', $xss.'Grp');
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'group', 'external_id' => '-1007777', 'title' => $xss,
            'messages' => [['role' => 'member', 'sender_id' => '77', 'sender' => $xss.'Name', 'content' => 'x', 'at' => now()->toIso8601String()]], 'message_count' => 1]);
        $client = Client::create(['name' => $xss.'Co', 'inn' => '301234567']);
        CompanyLink::create(['client_id' => $client->id, 'group_chat_id' => '-1007777', 'linked_by' => 'manager', 'linked_by_name' => $xss]);

        foreach ([route('admin.telegram-groups.index'), route('admin.telegram-groups.show', $group)] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($xss, $html, $url);
        }
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.index').'?q='.urlencode($xss))->assertOk()->assertDontSee($xss, false);
    }

    public function test_role_rules_match_the_invoice_settings(): void
    {
        $a = $this->group('-1001');
        $b = $this->group('-1002');
        $sales = $this->group('-100555');
        $alerts = $this->group('-100666');
        $gone = $this->group('-1003', 'Gone', ['bot_status' => 'left']);
        $json = $this->group('-1004');
        InvoiceParserSetting::current()->update(['json_chat_id' => '-1004']);
        $role = fn ($g, $role, $on = 1) => $this->actingAs($this->admin)->put(route('admin.telegram-groups.role', $g), ['role' => $role, 'on' => $on]);

        $role($alerts, 'invoice_chat')->assertSessionHasErrors('role');
        $role($sales, 'invoice_chat')->assertSessionHasErrors('role');
        $role($gone, 'invoice_chat')->assertSessionHasErrors('role');
        $role($gone, 'invoice_source')->assertSessionHasErrors('role');
        $role($json, 'invoice_chat')->assertSessionHasErrors('role');
        $role($json, 'invoice_source')->assertSessionHasErrors('role');
        $role($a, 'invoice_chat')->assertSessionHasNoErrors();
        $role($a, 'invoice_source')->assertSessionHasErrors('role');
        $role($b, 'invoice_source')->assertSessionHasNoErrors();
        $role($b, 'invoice_chat')->assertSessionHasErrors('role');
        $role($a, 'bogus')->assertSessionHasErrors('role');
        $role($a, 'invoice_chat', 'maybe')->assertSessionHasErrors('on');

        $s = InvoiceParserSetting::current()->refresh();
        $this->assertSame(['-1002', '-1001', '-1004'], [$s->source_chat_id, $s->target_chat_id, $s->json_chat_id]);

        // Removing a role this group does not have leaves the other group's role alone.
        $role($b, 'invoice_chat', 0);
        $this->assertSame('-1001', InvoiceParserSetting::current()->refresh()->target_chat_id);

        // The resulting config passes the Invoice parser settings validation.
        $this->actingAs($this->admin)->put(route('admin.invoice-parser.update'), ['source_chat_id' => $s->source_chat_id, 'target_chat_id' => $s->target_chat_id,
            'json_chat_id' => $s->json_chat_id])->assertSessionDoesntHaveErrors(['source_chat_id', 'target_chat_id', 'json_chat_id']);

        $private = TelegramChat::create(['chat_id' => '41', 'type' => 'private']);
        $role($private, 'invoice_chat')->assertNotFound();
    }

    public function test_members_survive_malformed_stored_messages(): void
    {
        $group = $this->group();
        $messages = ['junk', 5, null, ['role' => 'member'], ['role' => 'member', 'sender_id' => ['x']], ['role' => 'member', 'sender_id' => ''],
            ['role' => 'owner', 'sender_id' => '9'], ['role' => 'member', 'sender_id' => 77, 'sender' => 'Ok', 'at' => 123],
            ['role' => 'member', 'sender_id' => '78', 'sender' => null, 'at' => 'not a date']];
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'group', 'external_id' => '-1007777', 'messages' => $messages, 'message_count' => 9]);

        $members = $group->members();
        $this->assertSame(['77', '78'], $members->keys()->map(fn ($k) => (string) $k)->sort()->values()->all());
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $group))->assertOk()->assertSee('Ok');
    }

    public function test_members_with_an_array_sender_name(): void
    {
        $group = $this->group();
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'group', 'external_id' => '-1007777',
            'messages' => [['role' => 'member', 'sender_id' => '77', 'sender' => ['first' => 'x']]], 'message_count' => 1]);

        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $group))->assertOk();
    }

    public function test_muted_group_links_no_inn_and_invoice_source_still_reads_photos(): void
    {
        $group = $this->group('-1007777', 'Dealers', ['is_recorded' => false]);
        Client::create(['name' => 'Demo LLC', 'inn' => '301234567']);
        $this->groupPost(1, '-1007777', 'INN 301234567');
        $this->assertSame(0, CompanyLink::count());
        $this->assertSame(0, ChatConversation::where('channel', 'group')->count());

        // A muted group that becomes the invoice source still captures invoice photos.
        InvoiceParserSetting::current()->update(['enabled' => true, 'source_chat_id' => '-1007777', 'target_chat_id' => '-1008888']);
        app(TelegramBot::class)->handle(['update_id' => 2, 'message' => ['message_id' => 2, 'date' => time(),
            'chat' => ['id' => -1007777, 'type' => 'supergroup'], 'from' => ['id' => 41, 'first_name' => 'A'], 'photo' => [['file_id' => 'F1', 'width' => 10, 'height' => 10]]]]);
        $this->assertSame(1, InvoiceMessage::count());
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.show', $group))->assertOk()->assertSee('never recorded');
    }

    public function test_invoice_source_with_the_parser_switched_off_is_recorded_and_can_be_muted(): void
    {
        $group = $this->group();
        InvoiceParserSetting::current()->update(['enabled' => false, 'source_chat_id' => '-1007777']);
        // The parser is off, so it does not take the messages: the page offers the switch, as the recorder records them.
        $this->assertFalse($group->neverRecorded());
        $this->groupPost(1, '-1007777', 'Salom');
        $this->assertSame(1, ChatConversation::where('channel', 'group')->count());
        $group->update(['is_recorded' => false]);
        $this->groupPost(2, '-1007777', 'Yana');
        $this->assertSame(1, ChatConversation::where('channel', 'group')->sole()->message_count);

        InvoiceParserSetting::current()->update(['enabled' => true]);
        $this->assertTrue($group->refresh()->neverRecorded());
    }

    public function test_a_new_group_seen_by_the_bot_is_recorded_by_default(): void
    {
        $this->groupPost(1, '-1009999', 'Salom');
        $this->assertTrue(TelegramChat::where('chat_id', '-1009999')->sole()->is_recorded);
        $this->assertSame(1, ChatConversation::where('channel', 'group')->count());
    }

    public function test_leave_failure_paths(): void
    {
        $group = $this->group();
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $group))->assertSessionHasErrors('leave');
        $this->assertFalse($group->refresh()->isGone());

        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('down')]);
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $group))->assertSessionHasErrors('leave');

        config(['services.telegram.bot_token' => null]);
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $group))->assertSessionHasErrors('leave');

        // Alerts chat and invoice chat are refused too.
        config(['services.telegram.bot_token' => '123:abc']);
        $alerts = $this->group('-100666');
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $alerts))->assertSessionHasErrors('leave');
        InvoiceParserSetting::current()->update(['target_chat_id' => '-1007777']);
        $this->actingAs($this->admin)->post(route('admin.telegram-groups.leave', $group))->assertSessionHasErrors('leave');
    }

    public function test_menu_item_is_active_on_both_pages(): void
    {
        $group = $this->group();
        foreach ([route('admin.telegram-groups.index'), route('admin.telegram-groups.show', $group)] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#aria-label="Breadcrumb">\s*<p[^>]*>\s*Conversations\s*<span[^>]*>/</span>\s*Telegram groups#', $html, $url);
        }
    }

    public function test_search_with_like_wildcards(): void
    {
        $this->group('-1001', 'Sale 50% off');
        $this->group('-1002', 'Plain');
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.index', ['q' => '50%']))->assertOk()->assertSee('Sale 50% off', false);
        $this->actingAs($this->admin)->get(route('admin.telegram-groups.index', ['q' => '%']))->assertOk()->assertDontSee('>Plain<', false);
    }

    public function test_migration_backfill(): void
    {
        Schema::table('telegram_chats', fn ($t) => $t->dropColumn('is_recorded'));
        DB::table('telegram_chats')->insert(['chat_id' => '-100111', 'type' => 'supergroup', 'title' => 'Known', 'created_at' => now(), 'updated_at' => now()]);
        $conv = fn ($id, $title, $at) => DB::table('chat_conversations')->insert(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'group', 'external_id' => $id,
            'title' => $title, 'messages' => '[]', 'message_count' => 0, 'created_at' => $at, 'updated_at' => $at]);
        $conv('-100111', 'Renamed', now());
        $conv('-100222', 'Old title', now()->subDays(2));
        $conv('-100222', 'New title', now()->subDay());
        $conv('abc', 'Bad', now());
        $conv('12345', 'Positive', now());
        $conv('-100333', str_repeat('Ж', 300), now());
        $conv('-100444', null, now());

        (require database_path('migrations/2026_11_27_100000_add_management_to_telegram_chats_table.php'))->up();

        $rows = DB::table('telegram_chats')->orderBy('chat_id')->get()->keyBy('chat_id');
        $this->assertEqualsCanonicalizing(['-100111', '-100222', '-100333', '-100444'], DB::table('telegram_chats')->pluck('chat_id')->all());
        $this->assertSame('Known', $rows['-100111']->title);
        $this->assertSame('New title', $rows['-100222']->title);
        $this->assertSame(255, mb_strlen($rows['-100333']->title));
        $this->assertNull($rows['-100444']->title);
        $this->assertEquals(1, $rows['-100111']->is_recorded);

        (require database_path('migrations/2026_11_27_100000_add_management_to_telegram_chats_table.php'))->down();
        $this->assertFalse(Schema::hasColumn('telegram_chats', 'is_recorded'));
    }
}
