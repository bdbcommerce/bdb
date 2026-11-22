<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\ClientPerson;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA of the client portraits (companies, people, moves, AI prompt lines, "do not contact"). */
class ClientPortraitQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function person(array $attrs = []): ClientPerson
    {
        return ClientPerson::create($attrs + ['name' => 'Person', 'status' => 'active']);
    }

    // ---- access control -------------------------------------------------------------------------------------

    public function test_guests_are_redirected_and_non_admins_refused_on_every_route(): void
    {
        $client = Client::create(['name' => 'Oila', 'status' => 'active']);
        $p = $this->person(['client_id' => $client->id]);
        $routes = [
            ['get', route('admin.clients.index')], ['get', route('admin.clients.create')], ['get', route('admin.clients.show', $client)],
            ['get', route('admin.clients.edit', $client)], ['get', route('admin.client-people.create')], ['get', route('admin.client-people.edit', $p)],
            ['post', route('admin.clients.store')], ['put', route('admin.clients.update', $client)], ['delete', route('admin.clients.destroy', $client)],
            ['post', route('admin.client-people.store')], ['put', route('admin.client-people.update', $p)], ['delete', route('admin.client-people.destroy', $p)],
            ['post', route('admin.client-people.move', $p)],
        ];
        foreach ($routes as [$m, $url]) {
            $this->{$m}($url, ['name' => 'Z', 'status' => 'active'])->assertRedirect();
        }
        $user = User::factory()->create(['is_admin' => false]);
        foreach ($routes as [$m, $url]) {
            $status = $this->actingAs($user)->{$m}($url, ['name' => 'Z', 'status' => 'active'])->status();
            $this->assertTrue(in_array($status, [302, 403], true), "$m $url gave $status to a non-admin");
        }
        $this->assertSame(1, Client::count());
        $this->assertSame(1, ClientPerson::count());
        $this->assertSame('active', $p->fresh()->status);
    }

    public function test_admin_pages_render_and_the_sidebar_item_is_active(): void
    {
        $admin = $this->admin();
        $client = Client::create(['name' => 'Oila', 'status' => 'active']);
        $p = $this->person(['client_id' => $client->id]);
        foreach ([route('admin.clients.index'), route('admin.clients.create'), route('admin.clients.show', $client), route('admin.clients.edit', $client),
            route('admin.client-people.create'), route('admin.client-people.edit', $p)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#href="'.preg_quote(route('admin.clients.index'), '#').'"[^>]*aria-current="page"#s', $html, "sidebar not active on $url");
        }
        $this->actingAs($admin)->get('/admin/ai-team/clients')->assertNotFound();
    }

    // ---- validation -----------------------------------------------------------------------------------------

    public function test_company_validation_and_old_input(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.clients.create'))->post(route('admin.clients.store'), [
            'name' => str_repeat('я', 151), 'inn' => '30A', 'status' => 'nope', 'city' => 'Самарқанд', 'installed' => str_repeat('x', 3001),
        ])->assertRedirect(route('admin.clients.create'))->assertSessionHasErrors(['name', 'inn', 'status', 'installed']);
        $this->actingAs($admin)->get(route('admin.clients.create'))->assertSee('Самарқанд');
        $this->assertSame(0, Client::count());

        // Boundary: exactly at the limits, Cyrillic/Uzbek Latin, CRLF counts as one char.
        $this->actingAs($admin)->post(route('admin.clients.store'), ['name' => str_repeat('я', 150), 'inn' => '305 112 448', 'status' => 'prospect',
            'city' => 'Gʻijduvon', 'installed' => str_repeat("ab\r\n", 750)])->assertSessionHasNoErrors();
        $c = Client::sole();
        $this->assertSame(150, mb_strlen($c->name));
        $this->assertStringNotContainsString("\r", $c->installed);
        $this->assertCount(750, $c->installedItems());
    }

    public function test_person_validation_parts_statuses_and_telegram_lookup(): void
    {
        $admin = $this->admin();
        $tg = TelegramUser::create(['telegram_id' => '555']);
        $this->actingAs($admin)->from(route('admin.client-people.create'))->post(route('admin.client-people.store'), [
            'name' => '', 'part' => 'boss', 'status' => 'gone', 'client_id' => 99999, 'telegram_id' => 'abc', 'position' => 'Директор',
        ])->assertSessionHasErrors(['name', 'part', 'status', 'client_id', 'telegram_user_id']);
        // (assertSessionHasErrors hides the errors from the next request in tests, so post again for the page check)
        $this->actingAs($admin)->from(route('admin.client-people.create'))->post(route('admin.client-people.store'), ['name' => '', 'status' => 'active', 'telegram_id' => 'abc', 'position' => 'Директор']);
        $html = $this->actingAs($admin)->get(route('admin.client-people.create'))->assertOk()->getContent();
        $this->assertStringContainsString('Директор', $html, 'old position not kept');
        $this->assertStringContainsString('value="abc"', $html, 'old telegram_id not kept');
        $this->assertStringContainsString('No Telegram customer has this Telegram ID', $html, 'telegram error not shown');
        $this->assertSame(0, ClientPerson::count());

        $this->actingAs($admin)->post(route('admin.client-people.store'), ['name' => 'Ali', 'status' => 'vacation', 'telegram_id' => ' 555 ', 'notes' => "a\r\nb"])
            ->assertSessionHasNoErrors();
        $p = ClientPerson::sole();
        $this->assertSame([$tg->id, "a\nb"], [$p->telegram_user_id, $p->notes]);

        // Clearing the Telegram ID unlinks.
        $this->actingAs($admin)->put(route('admin.client-people.update', $p), ['name' => 'Ali', 'status' => 'active', 'telegram_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->telegram_user_id);
    }

    public function test_array_inputs_do_not_crash_the_forms(): void
    {
        $admin = $this->admin();
        $crashed = [];
        foreach ([['new_company' => ['x']], ['telegram_id' => ['1']], ['notes' => ['x']]] as $bad) {
            $status = $this->actingAs($admin)->post(route('admin.client-people.store'), $bad + ['name' => 'A', 'status' => 'active'])->status();
            $crashed[] = $status === 500 ? 'person '.array_key_first($bad) : null;
        }
        $status = $this->actingAs($admin)->post(route('admin.clients.store'), ['name' => 'A', 'status' => 'active', 'installed' => ['x']])->status();
        $crashed[] = $status === 500 ? 'company installed' : null;
        $this->assertSame([], array_values(array_filter($crashed)), 'array inputs give a server error');
    }

    // ---- search & pagination --------------------------------------------------------------------------------

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $admin = $this->admin();
        Client::create(['name' => '50% Market', 'status' => 'active']);
        Client::create(['name' => '500 Market', 'status' => 'active']);
        Client::create(['name' => 'A_B Savdo', 'status' => 'active']);
        Client::create(['name' => 'AXB Savdo', 'status' => 'active']);

        $html = $this->actingAs($admin)->get(route('admin.clients.index', ['q' => '50%']))->assertOk()->getContent();
        $this->assertStringContainsString('50% Market', $html, 'search "50%" does not find "50% Market"');
        $this->assertStringNotContainsString('500 Market', $html);
        $html = $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'A_B']))->assertOk()->getContent();
        $this->assertStringContainsString('A_B Savdo', $html, 'search "A_B" does not find "A_B Savdo"');
        $this->assertStringNotContainsString('AXB Savdo', $html);
    }

    public function test_search_by_inn_person_status_and_cyrillic_case(): void
    {
        $admin = $this->admin();
        $a = Client::create(['name' => 'Оила Маркет', 'inn' => '305112448', 'status' => 'active']);
        Client::create(['name' => 'Sifat', 'status' => 'prospect']);
        $this->person(['client_id' => $a->id, 'name' => 'Gulnora']);

        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => '3051']))->assertSee('Оила Маркет')->assertDontSee('Sifat');
        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'gulnora']))->assertSee('Оила Маркет')->assertDontSee('Sifat');
        $this->actingAs($admin)->get(route('admin.clients.index', ['status' => 'prospect']))->assertSee('Sifat')->assertDontSee('Оила Маркет');
        $this->actingAs($admin)->get(route('admin.clients.index', ['status' => 'bogus']))->assertOk()->assertSee('Sifat')->assertSee('Оила Маркет');
        $this->assertStringContainsString('Оила Маркет', $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'оила']))->getContent(), 'lower-case Cyrillic search does not find "Оила Маркет"');
    }

    public function test_pagination_keeps_the_query(): void
    {
        $admin = $this->admin();
        foreach (range(1, 25) as $i) {
            Client::create(['name' => sprintf('Firm %02d', $i), 'status' => 'active']);
        }
        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Firm']))->assertSee('Firm 20')->assertDontSee('Firm 21')->assertSee('q=Firm', false);
        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Firm', 'page' => 2]))->assertSee('Firm 25')->assertDontSee('Firm 01');
    }

    // ---- move flow -------------------------------------------------------------------------------------------

    public function test_move_chain_links_both_ways_and_current_picks_the_last_card(): void
    {
        $admin = $this->admin();
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        [$c1, $c2, $c3] = [Client::create(['name' => 'One', 'status' => 'active']), Client::create(['name' => 'Two', 'status' => 'active']), Client::create(['name' => 'Three', 'status' => 'active'])];
        $a1 = $this->person(['client_id' => $c1->id, 'telegram_user_id' => $tg->id, 'name' => 'Bekzod', 'style' => 'Technical', 'phone' => '+998901112233']);

        $this->actingAs($admin)->post(route('admin.client-people.move', $a1), ['client_id' => $c2->id])->assertSessionHasNoErrors();
        $a2 = ClientPerson::where('client_id', $c2->id)->sole();
        $this->actingAs($admin)->post(route('admin.client-people.move', $a2), ['client_id' => $c3->id])->assertSessionHasNoErrors();
        $a3 = ClientPerson::where('client_id', $c3->id)->sole();

        $this->assertSame(['left', 'left', 'active'], [$a1->fresh()->status, $a2->fresh()->status, $a3->status]);
        $this->assertSame([$a1->id, $a2->id], [$a2->previous_id, $a3->previous_id]);
        $this->assertSame(['Technical', '+998901112233', $tg->id], [$a3->style, $a3->phone, $a3->telegram_user_id]);
        $this->assertTrue(ClientPerson::current($tg)->is($a3));

        $this->actingAs($admin)->get(route('admin.clients.show', $c2))->assertSee('Earlier at')->assertSee('One')->assertSee('Now at')->assertSee('Three');
        $this->actingAs($admin)->get(route('admin.clients.show', $c3))->assertSee('Earlier at')->assertSee('Two');

        // Same company refused; unknown company refused.
        $this->actingAs($admin)->post(route('admin.client-people.move', $a3), ['client_id' => $c3->id])->assertSessionHasErrors('client_id');
        $this->actingAs($admin)->post(route('admin.client-people.move', $a3), ['client_id' => 99999])->assertSessionHasErrors('client_id');
        $this->assertSame(3, ClientPerson::count());

        // Deleting the middle card keeps the others (links become null, no FK error).
        $this->actingAs($admin)->delete(route('admin.client-people.destroy', $a2))->assertRedirect(route('admin.clients.show', $c2));
        $this->assertNull($a3->fresh()->previous_id);
        $this->actingAs($admin)->get(route('admin.clients.show', $c1))->assertOk()->assertDontSee('Now at');
    }

    public function test_a_card_that_already_left_cannot_be_moved_again(): void
    {
        $admin = $this->admin();
        [$c1, $c2, $c3] = [Client::create(['name' => 'One', 'status' => 'active']), Client::create(['name' => 'Two', 'status' => 'active']), Client::create(['name' => 'Three', 'status' => 'active'])];
        $a1 = $this->person(['client_id' => $c1->id, 'name' => 'Bekzod']);
        $this->actingAs($admin)->post(route('admin.client-people.move', $a1), ['client_id' => $c2->id]);
        // Moving the old ("left") card again forks the history: two "next" cards, two active people.
        $this->actingAs($admin)->post(route('admin.client-people.move', $a1->fresh()), ['client_id' => $c3->id]);
        $this->assertLessThanOrEqual(1, ClientPerson::where('previous_id', $a1->id)->count(), 'a "left" card was moved a second time');
    }

    public function test_do_not_contact_survives_a_move(): void
    {
        $admin = $this->admin();
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        [$c1, $c2] = [Client::create(['name' => 'One', 'status' => 'active']), Client::create(['name' => 'Two', 'status' => 'active'])];
        $a1 = $this->person(['client_id' => $c1->id, 'telegram_user_id' => $tg->id, 'status' => 'do_not_contact']);
        $conv = ChatConversation::create(['uuid' => 'u1', 'channel' => 'telegram', 'external_id' => '7001', 'locale' => 'uz', 'messages' => []]);
        $this->assertTrue(SalesAssistant::doNotContact($conv));

        $this->actingAs($admin)->post(route('admin.client-people.move', $a1), ['client_id' => $c2->id])->assertSessionHasNoErrors();
        $this->assertTrue(SalesAssistant::doNotContact($conv), '"Do not contact" is lost when the person moves (new card is active)');
    }

    // ---- deletes ---------------------------------------------------------------------------------------------

    public function test_people_of_a_deleted_company_can_still_be_reached(): void
    {
        $admin = $this->admin();
        $c = Client::create(['name' => 'Gone', 'status' => 'active']);
        $p = $this->person(['client_id' => $c->id, 'name' => 'Orphan Person']);
        $this->actingAs($admin)->delete(route('admin.clients.destroy', $c))->assertRedirect(route('admin.clients.index'));
        $this->assertNull($p->fresh()->client_id);
        $html = $this->actingAs($admin)->get(route('admin.clients.index'))->assertSee('1 person without a company')->getContent();
        $this->assertStringContainsString(route('admin.client-people.edit', $p), $html, 'people without a company are counted but not listed/linked anywhere');
    }

    public function test_deleting_a_telegram_user_unlinks_the_card(): void
    {
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        $p = $this->person(['telegram_user_id' => $tg->id]);
        $tg->delete();
        $this->assertNull($p->fresh()->telegram_user_id);
        $this->actingAs($this->admin())->get(route('admin.client-people.edit', $p))->assertOk();
    }

    // ---- escaping --------------------------------------------------------------------------------------------

    public function test_every_field_is_escaped(): void
    {
        $admin = $this->admin();
        $x = '<img src=x onerror=alert(1)>';
        $tg = TelegramUser::create(['telegram_id' => '7001', 'name' => $x, 'profile_name' => $x, 'company' => $x]);
        $c = Client::create(['name' => $x, 'industry' => $x, 'city' => $x, 'size' => $x, 'status' => 'active', 'since' => $x, 'owner' => $x,
            'installed' => $x, 'consumables' => $x, 'notes' => $x, 'inn' => '1']);
        $p = $this->person(['client_id' => $c->id, 'telegram_user_id' => $tg->id, 'name' => $x, 'position' => $x, 'department' => $x, 'style' => $x, 'phone' => $x, 'notes' => $x]);
        $other = Client::create(['name' => 'Two', 'status' => 'active']);
        $this->person(['client_id' => $other->id, 'previous_id' => $p->id, 'name' => $x]);
        ChatConversation::create(['uuid' => 'u1', 'channel' => 'telegram', 'external_id' => '7001', 'locale' => 'uz', 'messages' => [],
            'sales_state' => ['customer' => ['city' => $x, 'company' => $x], 'business_type' => $x]]);

        foreach ([route('admin.clients.index'), route('admin.clients.show', $c), route('admin.clients.edit', $c), route('admin.clients.show', $other),
            route('admin.client-people.edit', $p), route('admin.client-people.create', ['telegram_user' => $tg->id]), route('admin.telegram-users.show', $tg)] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee($x, false);
        }
    }

    public function test_company_notes_reach_the_ai_when_the_installed_list_is_long(): void
    {
        // Within the form limits (installed <= 3000), the 1500-char cap of the company line must not drop the notes for the AI team.
        $c = Client::create(['name' => 'Big', 'status' => 'active', 'installed' => implode("\n", array_fill(0, 140, 'Scanner Zebra DS2208')),
            'consumables' => 'Labels 58x40', 'notes' => 'Never call before 10:00']);
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        $this->person(['client_id' => $c->id, 'telegram_user_id' => $tg->id, 'name' => 'Me']);
        $this->assertLessThanOrEqual(3000, mb_strlen($c->installed));
        $this->assertStringContainsString('Never call before 10:00', implode("\n", ClientPerson::current($tg)->forPrompt()), 'company notes are cut off by a long installed list');
    }

    // ---- prompt ----------------------------------------------------------------------------------------------

    public function test_prompt_lines_are_capped_and_contain_no_phones(): void
    {
        $c = Client::create(['name' => 'Big', 'status' => 'active', 'installed' => implode("\n", array_fill(0, 300, 'Scanner Zebra DS2208')),
            'consumables' => str_repeat('c', 1000), 'notes' => str_repeat('n', 2000)]);
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        $me = $this->person(['client_id' => $c->id, 'telegram_user_id' => $tg->id, 'name' => 'Me', 'notes' => str_repeat('m', 2000), 'style' => str_repeat('s', 300), 'phone' => '+998901112233']);
        foreach (range(1, 30) as $i) {
            $this->person(['client_id' => $c->id, 'name' => "Colleague $i", 'phone' => '+99890000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'position' => str_repeat('p', 100)]);
        }
        $lines = ClientPerson::current($tg)->forPrompt();
        $this->assertCount(3, $lines);
        foreach ($lines as $l) {
            $this->assertLessThanOrEqual(1500, mb_strlen($l));
        }
        $this->assertStringNotContainsString('+99890', implode("\n", $lines));
        $this->assertSame(8, substr_count($lines[2], 'Colleague'));
        $this->assertTrue($me->is(ClientPerson::current($tg)));
    }

    public function test_current_ignores_left_and_dismissed_and_keeps_vacation_and_do_not_contact(): void
    {
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        $this->assertNull(ClientPerson::current($tg));
        $this->assertNull(ClientPerson::current(null));
        $vac = $this->person(['telegram_user_id' => $tg->id, 'status' => 'vacation']);
        $this->person(['telegram_user_id' => $tg->id, 'status' => 'left']);
        $this->person(['telegram_user_id' => $tg->id, 'status' => 'fired']);
        $this->assertTrue(ClientPerson::current($tg)->is($vac));
        $this->assertStringContainsString('status: on leave', ClientPerson::current($tg)->forPrompt()[0]);
        $dnc = $this->person(['telegram_user_id' => $tg->id, 'status' => 'do_not_contact']);
        $this->assertTrue(ClientPerson::current($tg)->is($dnc));
        $this->assertSame(1, count($dnc->forPrompt()), 'no company → only the person line');
    }

    public function test_do_not_contact_only_matches_the_right_customer(): void
    {
        $tg = TelegramUser::create(['telegram_id' => '7001']);
        TelegramUser::create(['telegram_id' => '7002']);
        $this->person(['telegram_user_id' => $tg->id, 'status' => 'do_not_contact']);
        $mine = ChatConversation::create(['uuid' => 'u1', 'channel' => 'business', 'external_id' => '7001', 'locale' => 'uz', 'messages' => []]);
        $other = ChatConversation::create(['uuid' => 'u2', 'channel' => 'telegram', 'external_id' => '7002', 'locale' => 'uz', 'messages' => []]);
        $none = ChatConversation::create(['uuid' => 'u3', 'channel' => 'web', 'external_id' => null, 'locale' => 'uz', 'messages' => []]);
        $this->assertTrue(SalesAssistant::doNotContact($mine));
        $this->assertFalse(SalesAssistant::doNotContact($other));
        $this->assertFalse(SalesAssistant::doNotContact($none));
        $settings = SalesAssistantSetting::current();
        $other->sales_state = ['follow_up_at' => now()->subDay()->toIso8601String()];
        $mine->sales_state = ['follow_up_at' => now()->subDay()->toIso8601String()];
        $this->assertFalse(SalesAssistant::followUpDue($mine, $settings));
    }

    // ---- prefill from Telegram -------------------------------------------------------------------------------

    public function test_prefill_matches_an_existing_company_and_handles_missing_data(): void
    {
        $admin = $this->admin();
        $c = Client::create(['name' => 'Yangi Savdo', 'status' => 'active']);
        $tg = TelegramUser::create(['telegram_id' => '7001', 'profile_name' => 'Akmal R', 'company' => 'Yangi Savdo']);
        $this->actingAs($admin)->get(route('admin.client-people.create', ['telegram_user' => $tg->id]))->assertOk()
            ->assertSee('value="Akmal R"', false)->assertSee('<option value="'.$c->id.'" selected', false);

        $bare = TelegramUser::create(['telegram_id' => '7002']);
        $this->actingAs($admin)->get(route('admin.client-people.create', ['telegram_user' => $bare->id]))->assertOk();
        $this->actingAs($admin)->get(route('admin.client-people.create', ['telegram_user' => 99999]))->assertOk();
        $this->actingAs($admin)->get(route('admin.client-people.create', ['client' => 99999]))->assertOk();
    }
}
