<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\ClientPerson;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Client portraits: companies and their people, moves between companies, what the AI reads, "do not contact". */
class ClientPortraitTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private ClientPortraitTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode(['reply' => 'Etiketka kerakmi?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'questions',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => 'supermarket', 'software' => '',
                    'items' => [['product' => 'Labels', 'details' => '']], 'summary' => 'Labels.', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false,
                    'follow_up_agreed' => false, 'hand_to' => '']), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function bot(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Akmal']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_company_people_and_a_move_to_another_company(): void
    {
        $admin = $this->admin();
        $tg = TelegramUser::create(['telegram_id' => '7001', 'name' => 'Akmal', 'phone' => '+998901112233']);
        $this->actingAs($admin)->post(route('admin.clients.store'), ['name' => 'Oila Market', 'inn' => '305 112 448', 'industry' => 'Supermarkets', 'city' => 'Tashkent',
            'size' => '3 branches', 'status' => 'active', 'installed' => "9 POS terminals\r\n3 label printers", 'notes' => 'Opening a 4th branch'])->assertRedirect();
        $oila = Client::sole();
        $this->assertSame(['9 POS terminals', '3 label printers'], $oila->installedItems());

        $this->actingAs($admin)->post(route('admin.client-people.store'), ['client_id' => $oila->id, 'name' => 'Akmal Rasulov', 'position' => 'Owner', 'part' => 'decides',
            'status' => 'active', 'telegram_id' => '7001', 'style' => 'Short, direct, Russian'])->assertRedirect(route('admin.clients.show', $oila));
        $this->actingAs($admin)->post(route('admin.client-people.store'), ['client_id' => $oila->id, 'name' => 'Gulnora', 'position' => 'Chief accountant', 'part' => 'pays', 'status' => 'active']);
        $this->actingAs($admin)->post(route('admin.client-people.store'), ['name' => 'X', 'status' => 'active', 'telegram_id' => '999'])->assertSessionHasErrors('telegram_user_id');
        $akmal = ClientPerson::where('name', 'Akmal Rasulov')->sole();
        $this->assertSame($tg->id, $akmal->telegram_user_id);

        $this->actingAs($admin)->get(route('admin.clients.show', $oila))->assertOk()->assertSee('Akmal Rasulov')->assertSee('Decides')->assertSee('9 POS terminals');
        $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Gulnora']))->assertOk()->assertSee('Oila Market');
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $tg))->assertOk()->assertSee('Client portrait')->assertSee('Akmal Rasulov');

        // Akmal moves to Sifat Pharm: the old card becomes "left", a new one starts there.
        $sifat = Client::create(['name' => 'Sifat Pharm', 'status' => 'prospect']);
        $this->actingAs($admin)->post(route('admin.client-people.move', $akmal), ['client_id' => $sifat->id, 'position' => 'Director'])->assertRedirect(route('admin.clients.show', $sifat));
        $this->assertSame('left', $akmal->fresh()->status);
        $new = ClientPerson::where('client_id', $sifat->id)->sole();
        $this->assertSame([$akmal->id, $tg->id, 'Director', 'active'], [$new->previous_id, $new->telegram_user_id, $new->position, $new->status]);
        $this->assertTrue(ClientPerson::current($tg)->is($new));
        $this->actingAs($admin)->get(route('admin.clients.show', $oila))->assertSee('Now at')->assertSee('Sifat Pharm');
        $this->actingAs($admin)->post(route('admin.client-people.move', $new), ['client_id' => $sifat->id])->assertSessionHasErrors('client_id');

        // Deleting a company keeps its people.
        $this->actingAs($admin)->delete(route('admin.clients.destroy', $oila));
        $this->assertNull(ClientPerson::where('name', 'Gulnora')->sole()->client_id);
    }

    public function test_the_ai_reads_the_portrait_and_do_not_contact_stops_reminders(): void
    {
        $tg = TelegramUser::create(['telegram_id' => '7001', 'name' => 'Akmal']);
        $oila = Client::create(['name' => 'Oila Market', 'industry' => 'Supermarkets', 'city' => 'Tashkent', 'status' => 'active', 'since' => '2024',
            'installed' => "9 POS terminals\n3 label printers", 'consumables' => 'Labels 58x40, 20 rolls a month', 'notes' => 'Dislikes long messages']);
        $akmal = ClientPerson::create(['client_id' => $oila->id, 'telegram_user_id' => $tg->id, 'name' => 'Akmal Rasulov', 'position' => 'Owner', 'part' => 'decides',
            'status' => 'active', 'style' => 'Short, direct', 'phone' => '+998901112233']);
        ClientPerson::create(['client_id' => $oila->id, 'name' => 'Gulnora', 'position' => 'Chief accountant', 'part' => 'pays', 'status' => 'active']);
        ClientPerson::create(['client_id' => $oila->id, 'name' => 'Bekzod', 'position' => 'IT', 'status' => 'left']);

        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent')); // Monday
        $this->bot(1, 'Etiketka kerak');
        $data = $this->calls[0];
        $this->assertStringContainsString('Client portrait: Akmal Rasulov — Owner; in decisions: decides; how to talk to them: Short, direct', $data);
        $this->assertStringContainsString('Company: Oila Market, Supermarkets, Tashkent; active customer since 2024. Notes: Dislikes long messages. Consumables: Labels 58x40, 20 rolls a month. Installed: 9 POS terminals; 3 label printers', $data);
        $this->assertStringContainsString('Other people there: Gulnora (Chief accountant, pays)', $data);
        $this->assertStringNotContainsString('Bekzod', $data, 'people who left are not listed');
        $this->assertStringNotContainsString('+998901112233', $data, 'no phone from the portrait');

        // "Do not contact": no reminder; the unfinished request still reaches the managers.
        $akmal->update(['status' => 'do_not_contact']);
        $c = ChatConversation::sole();
        $settings = SalesAssistantSetting::current();
        $this->assertNull(SalesAssistant::nudgeDue($c, $settings, now()->addHours($settings->remind_after_hours + 1)));
        $this->assertSame('close', SalesAssistant::nudgeDue($c, $settings, now()->addHours($settings->close_after_hours + 1)));
        $c->sales_state = ['follow_up_at' => now()->subDay()->toIso8601String()] + $c->sales_state;
        $this->assertFalse(SalesAssistant::followUpDue($c, $settings));
    }

    public function test_create_a_portrait_from_the_chat_and_access(): void
    {
        $admin = $this->admin();
        $tg = TelegramUser::create(['telegram_id' => '7001', 'name' => 'Akmal', 'phone' => '+998901112233', 'company' => 'Yangi Savdo']);
        ChatConversation::create(['uuid' => 'u1', 'channel' => 'telegram', 'external_id' => '7001', 'locale' => 'uz', 'messages' => [],
            'sales_state' => ['customer' => ['city' => 'Namangan'], 'business_type' => 'shop']]);

        $this->actingAs($admin)->get(route('admin.telegram-users.show', $tg))->assertOk()->assertSee('Create a portrait');
        $this->actingAs($admin)->get(route('admin.client-people.create', ['telegram_user' => $tg->id]))->assertOk()
            ->assertSee('value="Akmal"', false)->assertSee('value="Yangi Savdo"', false)->assertSee('value="Namangan"', false)->assertSee('value="7001"', false);
        $this->actingAs($admin)->post(route('admin.client-people.store'), ['name' => 'Akmal', 'status' => 'active', 'telegram_id' => '7001',
            'new_company' => 'Yangi Savdo', 'new_city' => 'Namangan', 'new_industry' => 'shop'])->assertRedirect();
        $this->assertSame(['Yangi Savdo', 'Namangan', 'shop', 'prospect'], array_values(Client::sole()->only(['name', 'city', 'industry', 'status'])));
        $this->assertSame('Yangi Savdo', ClientPerson::current($tg)->client->name);

        $this->actingAs($admin)->post(route('admin.clients.store'), ['name' => '<b>X</b>', 'status' => 'active', 'inn' => 'abc'])->assertSessionHasErrors('inn');
        auth()->logout();
        $this->get(route('admin.clients.index'))->assertRedirect();
        $this->post(route('admin.clients.store'), ['name' => 'Z', 'status' => 'active'])->assertRedirect();
        $this->assertSame(1, Client::count());
    }
}
