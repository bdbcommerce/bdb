<?php

namespace Tests\Feature;

use App\Models\CatalogClient;
use App\Models\CatalogProduct;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\Client;
use App\Models\CompanyItem;
use App\Models\CompanyLink;
use App\Models\InvoiceParserSetting;
use App\Models\OnecPerson;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Companies\CompanyLookup;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Companies: by INN (our base → 1C → registry), links to contacts and groups, price type, usual / sales-plan lists. */
class CompanyBaseTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answer = [];

    public array $oneC = ['name' => 'Korzinka LLC', 'address' => 'Tashkent, Amir Temur 1', 'director' => 'A. Karimov', 'price_type' => 'Дилер'];

    public int $oneCStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test',
            'services.onec' => ['url' => 'https://1c.test/hs', 'login' => 'bot', 'password' => 'secret', 'company_path' => '/clients/newClient', 'companies_path' => '/crm/company', 'items_path' => '/crm/item', 'company_method' => 'POST',
                'company_field' => 'stir', 'purchases_path' => '/companies/{inn}/purchases', 'timeout' => 5],
            'services.registry' => ['url' => 'https://registry.test/api/{inn}', 'token' => 'reg-token', 'timeout' => 5]]);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        $test = $this;
        Http::fake([
            // Read only: the 1C company list. clients/newClient CREATES in 1C (admin button only).
            '1c.test/hs/crm/company' => fn () => Http::response(['count' => $test->oneCStatus === 200 ? 2 : 0, 'data' => $test->oneCStatus === 200 ? [
                $test->oneC + ['id' => 'guid-korzinka', 'code' => 614, 'fullName' => 'KORZINKA MCHJ', 'stir' => '301234567', 'mob' => '', 'one_time' => true],
                ['id' => 'guid-person', 'code' => 1021, 'name' => 'Abdullox', 'fullName' => 'Abdullox', 'stir' => '', 'mob' => '+998910200302', 'address' => '', 'one_time' => false],
            ] : []]),
            '1c.test/hs/crm/item' => Http::response(['count' => 2, 'data' => [
                ['id' => 'item-1', 'code' => 306, 'name' => 'Scanner Zebra 2D DS2208/Korzinka', 'acc_name' => 'Сканер Zebra DS2208', 'short_name' => '', 'mxik' => '084', 'barcode' => '', 'unit' => 'шт', 'company_id' => 'guid-korzinka', 'category_id' => 1, 'description' => ''],
                ['id' => 'item-2', 'code' => 113, 'name' => 'TR 80x60 Nishon', 'acc_name' => 'Термо бумага', 'short_name' => 'Чековая лента', 'mxik' => '048', 'barcode' => '', 'unit' => 'шт', 'description' => ''],
            ]]),
            '1c.test/hs/clients/newClient' => Http::response(['count' => 1, 'data' => [['id' => 'guid-new', 'code' => 1400, 'name' => 'NEW CO MCHJ', 'fullName' => 'NEW CO', 'stir' => '302000001', 'mob' => '', 'address' => 'Tashkent']]]),
            '1c.test/hs/companies/*/purchases*' => Http::response(['purchases' => [['sku' => 'RIB110300', 'qty' => 10, 'date' => '2026-11-01']]]),
            'registry.test/*' => Http::response(['data' => ['full_name' => 'Registry Company LLC', 'legal_address' => 'Samarkand']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]]),
        ]);
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private CompanyBaseTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data', 'images');

                return ['text' => json_encode($this->test->answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        $this->answer = $this->reply();
    }

    private function reply(array $override = []): array
    {
        return $override + ['reply' => 'Ok', 'language' => 'ru', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'inns' => []];
    }

    private function say(int $id, array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function toSales(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === '-100555')->values()->all();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'name' => 'Nodira']);
    }

    // ---- Lookup -------------------------------------------------------------------------------------

    public function test_lookup_reads_the_1c_company_list_never_creates_in_1c(): void
    {
        $company = app(CompanyLookup::class)->find('301 234 567', byCustomer: true);

        $this->assertSame('Korzinka LLC', $company->name);
        $this->assertSame(['301234567', '1c', 'dealer', 'A. Karimov', 614, 'guid-korzinka'], [$company->inn, $company->source, $company->price_type, $company->director, $company->onec_code, $company->onec_id]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://1c.test/hs/crm/company' && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Basic '.base64_encode('bot:secret')));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'newClient'));

        // Second time: our base, no remote call.
        $sent = count(Http::recorded());
        $this->assertTrue(app(CompanyLookup::class)->find('301234567')->is($company));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_unknown_in_1c_falls_back_to_the_registry_then_to_the_inn_only(): void
    {
        $this->oneCStatus = 404;
        $company = app(CompanyLookup::class)->find('302000001');
        $this->assertSame(['Registry Company LLC', 'registry', 'Samarkand'], [$company->name, $company->source, $company->address]);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://registry.test/api/302000001') && $r->hasHeader('Authorization', 'Bearer reg-token'));

        config(['services.registry.url' => null]);
        $this->assertSame(['INN 302000002', 'local'], [($c = app(CompanyLookup::class)->find('302000002'))->name, $c->source]);
        $this->expectExceptionMessage('An INN is 9 digits (a sole trader: 12 or 14).');
        app(CompanyLookup::class)->find('12345');
    }

    // ---- In the chat --------------------------------------------------------------------------------

    public function test_an_inn_in_the_chat_links_the_company_but_does_not_unlock_prices(): void
    {
        CatalogClient::create(['phone' => '+998900000000', 'price_group' => 'partner', 'company' => 'Other']); // unrelated
        $this->say(1, ['text' => 'Salom, bizning STIR: 301 234 567, printer kerak']);

        $user = TelegramUser::where('telegram_id', '7001')->sole();
        $link = CompanyLink::sole();
        $this->assertSame([$user->id, 'ai'], [$link->telegram_user_id, $link->linked_by]);
        $this->assertSame('Korzinka LLC', $link->client->name);
        $this->assertStringContainsString('Company by INN', collect($this->toSales())->pluck('text')->implode("\n"));
        // An AI link: the company's dealer price type does not apply.
        $this->assertSame('end_user', CatalogClient::priceGroupFor($user));

        // The next turn sees the company.
        $this->say(2, ['text' => 'Narxi qancha?']);
        $this->assertStringContainsString('Company (the customer says, not confirmed yet): {"name":"Korzinka LLC","inn":"301234567"', end($this->calls)['data']);

        // A manager confirms: dealer prices now.
        $this->actingAs($this->admin())->post(route('admin.companies.confirm-link', $link))->assertSessionHasNoErrors();
        $this->assertSame('dealer', CatalogClient::priceGroupFor($user->refresh()));
    }

    public function test_inns_claude_read_from_a_photo_are_linked_too_and_capped(): void
    {
        $this->answer = $this->reply(['inns' => ['301234567', 'abc', '12', '301234567']]);
        $this->say(1, ['text' => 'Rekvizitlar rasmda']);
        $this->assertSame(1, CompanyLink::count());
        $this->assertSame('301234567', CompanyLink::sole()->client->inn);
    }

    public function test_higher_group_wins_between_the_company_and_the_1c_phone_list(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7001', 'phone' => '+998901112233', 'phone_verified_at' => now()]);
        CatalogClient::create(['phone' => '+998901112233', 'price_group' => 'dealer', 'company' => 'Dealer Co']);
        $company = Client::create(['name' => 'Partner Co', 'inn' => '303000003', 'price_type' => 'partner', 'status' => 'active']);
        app(CompanyLookup::class)->link($company, $user, null, 'manager', 'Nodira');
        $this->assertSame('partner', CatalogClient::priceGroupFor($user));
        $company->update(['price_type' => null]);
        $this->assertSame('dealer', CatalogClient::priceGroupFor($user));
    }

    public function test_a_confirmed_request_moves_plan_items_and_the_lead_shows_the_plan(): void
    {
        CatalogProduct::create(['sku' => 'ZD421T', 'brand' => 'Zebra', 'model' => 'ZD421t', 'category' => 'label_printer', 'name' => ['ru' => 'Zebra ZD421t'],
            'prices' => ['end_user' => 1, 'dealer' => 1, 'partner' => 1], 'stock_status' => 'in_stock', 'is_active' => true, 'in_feed' => true]);
        $user = TelegramUser::create(['telegram_id' => '7001', 'phone' => '+998901112233']);
        $company = Client::create(['name' => 'Korzinka LLC', 'inn' => '301234567', 'status' => 'active']);
        $company->items()->create(['list' => 'plan', 'kind' => 'sku', 'value' => 'ZD421T']);
        $company->items()->create(['list' => 'plan', 'kind' => 'category', 'value' => 'scanner']);
        app(CompanyLookup::class)->link($company, $user, null, 'manager');

        $this->answer = $this->reply(['confirmed' => true, 'stage' => 'confirm', 'summary' => '2 label printers',
            'items' => [['product' => 'Zebra ZD421t printer', 'details' => '2 pcs']],
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '+998901112233', 'city' => '', 'company' => '']]);
        $this->say(1, ['text' => 'Tasdiqlayman']);

        $lead = ChatLead::sole();
        $moved = CompanyItem::where('value', 'ZD421T')->sole();
        $this->assertSame(['usual', 'Request #'.$lead->id], [$moved->list, $moved->moved_by]);
        $this->assertSame('plan', CompanyItem::where('value', 'scanner')->sole()->list);
        $html = collect($this->toSales())->pluck('text')->first(fn ($t) => str_contains($t, 'New request'));
        $this->assertStringContainsString('Company:</b> Korzinka LLC, INN 301234567', $html);
        $this->assertStringContainsString('Sales plan for this company:</b> Barcode scanners', $html);
    }

    public function test_an_inn_in_a_group_links_the_group(): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => 50, 'message' => ['message_id' => 5, 'date' => now()->getTimestamp(), 'text' => 'ИНН 301234567 для счёта',
            'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'Korzinka & BDB'], 'from' => ['id' => 42, 'first_name' => 'Aziz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $link = CompanyLink::sole();
        $this->assertSame(['-100777', null, 'ai'], [$link->group_chat_id, $link->telegram_user_id, $link->linked_by]);
        $this->assertSame('Korzinka & BDB', $link->groupTitle());
    }

    // ---- Admin ---------------------------------------------------------------------------------------

    public function test_admin_finds_by_inn_links_contacts_and_groups_and_manages_the_lists(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.companies.lookup'), ['inn' => '12'])->assertSessionHasErrors('inn');
        $this->actingAs($admin)->post(route('admin.companies.lookup'), ['inn' => '301-234-567'])->assertRedirect();
        $company = Client::sole();

        $user = TelegramUser::create(['telegram_id' => '555', 'username' => 'aziz_uz', 'profile_name' => 'Aziz']);
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'group', 'external_id' => '-100777', 'title' => 'Korzinka group', 'locale' => 'ru', 'messages' => []]);
        $this->actingAs($admin)->post(route('admin.companies.link', $company), ['contact' => '@aziz_uz'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.companies.link', $company), ['group_chat_id' => '-100777'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.companies.link', $company), ['contact' => '@nobody'])->assertSessionHasErrors('contact');
        $this->assertSame(['manager', 'Nodira'], [CompanyLink::where('telegram_user_id', $user->id)->sole()->linked_by, CompanyLink::where('telegram_user_id', $user->id)->sole()->linked_by_name]);

        CatalogProduct::create(['sku' => 'RIB110300', 'brand' => 'Armor', 'model' => 'AWR 8', 'category' => 'ribbon', 'name' => ['ru' => 'Ribbon'],
            'prices' => ['end_user' => 1, 'dealer' => 1, 'partner' => 1], 'stock_status' => 'in_stock', 'is_active' => true, 'in_feed' => true]);
        $this->actingAs($admin)->post(route('admin.companies.add-item', $company), ['list' => 'plan', 'kind' => 'sku', 'value' => 'RIB110300'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.companies.add-item', $company), ['list' => 'usual', 'kind' => 'category', 'value' => 'label_printer'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.companies.add-item', $company), ['list' => 'usual', 'kind' => 'sku', 'value' => 'NOPE'])->assertSessionHasErrors('value');
        $this->actingAs($admin)->post(route('admin.companies.add-item', $company), ['list' => 'usual', 'kind' => 'sku', 'value' => 'RIB110300'])->assertSessionHasErrors('value');

        $page = $this->actingAs($admin)->get(route('admin.clients.show', $company))->assertOk();
        $page->assertSee('Korzinka group')->assertSee('Aziz')->assertSee('Sales plan')->assertSee('RIB110300 — Armor AWR 8')->assertSee('Label printers')->assertSee('Price type: Dealer');

        // 1C purchases: the ribbon bought → usually orders.
        $this->actingAs($admin)->post(route('admin.companies.purchases', $company))->assertSessionHas('success', fn ($m) => str_contains($m, 'Moved to usually orders'));
        $this->assertSame(['usual', '1C purchase'], [($i = CompanyItem::where('value', 'RIB110300')->sole())->list, $i->moved_by]);

        $this->actingAs($admin)->delete(route('admin.companies.remove-item', $i))->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.companies.unlink', CompanyLink::where('group_chat_id', '-100777')->sole()))->assertRedirect();
        $this->assertSame(1, CompanyLink::count());

        // The contact page links by INN.
        $this->actingAs($admin)->post(route('admin.companies.link-contact', $user), ['inn' => '302000001'])->assertSessionHasNoErrors();
        $this->assertSame(2, CompanyLink::where('telegram_user_id', $user->id)->count());
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()->assertSee('Korzinka LLC')->assertSee('Link company');
    }

    public function test_admin_creates_in_1c_and_loads_the_1c_lists(): void
    {
        $admin = $this->admin();
        $company = Client::create(['name' => 'New Co', 'inn' => '302000001', 'status' => 'prospect']);
        $this->actingAs($admin)->get(route('admin.clients.show', $company))->assertSee('Create in 1C');
        $this->actingAs($admin)->post(route('admin.companies.create-in-1c', $company))->assertSessionHas('success', 'Created in 1C (client code 1400).');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://1c.test/hs/clients/newClient' && $r->method() === 'POST' && $r->data() === ['stir' => '302000001']);
        $this->assertSame([1400, 'guid-new', 'NEW CO MCHJ'], [$company->refresh()->onec_code, $company->onec_id, $company->name]);

        $this->actingAs($admin)->post(route('admin.companies.import'))
            ->assertSessionHas('success', 'From 1C: 1 companies added, 0 updated; 1 private persons (no STIR) saved for matching contacts by phone; 0 without a usable number not loaded.');
        $this->actingAs($admin)->post(route('admin.companies.import-items'))->assertSessionHas('success', 'Items from 1C: 2 saved.');
        $korzinka = Client::where('inn', '301234567')->sole();
        $this->actingAs($admin)->get(route('admin.clients.show', $korzinka))->assertOk()->assertSee('Their items in 1C')->assertSee('Scanner Zebra 2D DS2208/Korzinka');
        $this->actingAs($admin)->post(route('admin.companies.add-item', $korzinka), ['list' => 'usual', 'kind' => 'item1c', 'value' => '306'])->assertSessionHasNoErrors();
        $this->assertSame('Scanner Zebra 2D DS2208/Korzinka', CompanyItem::sole()->label());
        $this->actingAs($admin)->post(route('admin.companies.add-item', $korzinka), ['list' => 'plan', 'kind' => 'item1c', 'value' => '999'])->assertSessionHasErrors('value');
    }

    public function test_1c_private_persons_are_matched_to_contacts_by_phone(): void
    {
        $this->actingAs($admin = $this->admin())->post(route('admin.companies.import'))->assertSessionHasNoErrors();
        $person = OnecPerson::sole();
        $this->assertSame(['Abdullox', '+998910200302', 1021], [$person->name, $person->phone, $person->code]);
        $user = TelegramUser::create(['telegram_id' => '556', 'phone' => '+998 91 020 03 02']);
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()
            ->assertSee('1C client (private person):')->assertSee('Abdullox')->assertSee('not verified');
        $this->assertSame('end_user', CatalogClient::priceGroupFor($user));
    }

    public function test_create_client_in_1c_is_a_demo_that_sends_nothing(): void
    {
        $user = TelegramUser::create(['telegram_id' => '555', 'name' => 'Ali Valiev', 'phone' => '+998901112233']);
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()->assertSee('Create client in 1C (private person) · demo');
        $sent = count(Http::recorded());
        $this->actingAs($admin)->post(route('admin.companies.onec-client', $user))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Demo only') && str_contains($m, 'name: Ali Valiev, phone: +998901112233'));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_guests_cannot_use_the_company_routes(): void
    {
        $company = Client::create(['name' => 'X', 'status' => 'active']);
        $this->post(route('admin.companies.lookup'), ['inn' => '301234567'])->assertRedirect(route('admin.login'));
        $this->post(route('admin.companies.add-item', $company), ['list' => 'plan', 'kind' => 'category', 'value' => 'scanner'])->assertRedirect(route('admin.login'));
        $this->assertSame(0, CompanyItem::count());
    }
}
