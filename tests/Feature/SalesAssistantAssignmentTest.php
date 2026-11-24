<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** Contact types, the greeting for contacts not assigned yet, and silence when the beginning of a talk is unknown. */
class SalesAssistantAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    /** @var list<array{kind: string, system: string, data: string}> */
    public array $calls = [];

    /** @var array<string, mixed> overrides for the next answers */
    public array $greeting = [];

    public array $turn = [];

    public bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test', 'app.url' => 'https://bdb.test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on', 'require_assignment' => true]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent')); // Tuesday, working hours

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantAssignmentTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $greeting = count($schema['required']) === 3;
                $this->test->calls[] = ['kind' => $greeting ? 'greeting' : 'turn', 'system' => $system, 'data' => $data];
                if ($this->test->fail) {
                    throw new RuntimeException('overloaded');
                }
                $answer = $greeting
                    ? $this->test->greeting + ['reply' => 'Assalomu alaykum! BDB — avtomatik identifikatsiya. Menejer tez orada bogʻlanadi.', 'language' => 'uz', 'context_unknown' => false]
                    : $this->test->turn + ['reply' => 'Qanday biznesingiz bor?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
                        'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                        'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'context_unknown' => false];

                return ['text' => json_encode($answer), 'model' => $model, 'input_tokens' => 10, 'output_tokens' => 10];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function botUpdate(int $id, string $text, array $extra = []): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $extra + ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali', 'username' => 'ali_uz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => 7002, 'first_name' => 'Vali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<array> */
    private function sentTo(string $chatId): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === $chatId)->values()->all();
    }

    private function user(string $id = '7001'): TelegramUser
    {
        return TelegramUser::where('telegram_id', $id)->sole();
    }

    public function test_a_new_contact_is_greeted_once_and_the_sales_chat_is_asked_to_assign(): void
    {
        $this->botUpdate(1, 'Salom, etiketka kerak');
        $this->botUpdate(2, 'Narxi qancha?');
        $this->botUpdate(3, 'Javob bering');

        $this->assertSame(['greeting'], array_column($this->calls, 'kind'), 'one greeting, no sales turn');
        $this->assertStringContainsString('# COMPANY DATA', $this->calls[0]['system']);
        $this->assertStringContainsString('Salom, etiketka kerak', $this->calls[0]['data']);
        $toCustomer = $this->sentTo('7001');
        $this->assertCount(1, $toCustomer);
        $this->assertStringContainsString('BDB', $toCustomer[0]['text']);

        $toSales = $this->sentTo('-100555');
        $this->assertCount(1, $toSales, 'asked once');
        $this->assertStringContainsString('New contact — please assign', $toSales[0]['text']);
        $this->assertStringContainsString('/admin/telegram-users/', $toSales[0]['text']);

        $user = $this->user();
        $this->assertNull($user->contact_type);
        $this->assertNotNull($user->greeted_at);
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertTrue(collect($c->messages)->contains(fn ($m) => ! empty($m['greeting'])));
        $this->assertTrue($c->awaiting_reply, 'the greeting is not an answer');

        // Assigned as a client: the next message gets the sales assistant.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'client'])->assertRedirect();
        $this->assertSame('client', $user->fresh()->contact_type);
        $this->assertNotNull($user->fresh()->assigned_at);
        $this->botUpdate(4, 'Tarozi uchun');
        $this->assertSame(['greeting', 'turn'], array_column($this->calls, 'kind'));
    }

    public function test_suppliers_get_the_greeting_only_and_employees_nothing(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'supplier', 'source' => 'bot']);
        $this->botUpdate(1, 'Salom, yangi narxlar');
        $this->botUpdate(2, 'Koʻrdingizmi?');
        $this->assertSame(['greeting'], array_column($this->calls, 'kind'));
        $this->assertCount(1, $this->sentTo('7001'));
        $this->assertSame([], $this->sentTo('-100555'), 'a supplier is already assigned');

        $this->user()->update(['contact_type' => 'employee']);
        $this->botUpdate(3, 'Salom');
        $this->assertCount(1, $this->calls);
        $this->assertTrue(SalesAssistantSetting::current()->isEmployee($this->user()));
    }

    public function test_no_greeting_when_the_message_continues_a_talk_the_ai_never_saw(): void
    {
        $this->greeting = ['reply' => '', 'context_unknown' => true];
        $this->botUpdate(1, 'Ok, ertaga hisob-fakturani yuboring');
        $this->botUpdate(2, 'Iltimos');

        $this->assertCount(1, $this->calls, 'the rest of the session stays silent');
        $this->assertSame([], $this->sentTo('7001'));
        $this->assertNull($this->user()->greeted_at);
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertNotEmpty($c->sales_state['context_unknown']);
        $this->assertTrue($c->awaiting_reply);
    }

    public function test_a_client_replying_to_a_message_we_never_recorded_is_left_to_the_managers(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $this->botUpdate(10, 'Shu tovar qachon keladi?', ['reply_to_message' => ['message_id' => 3, 'text' => 'old']]);
        $this->botUpdate(11, 'Javob bering');
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->sentTo('7001'));
        $this->assertSame('reply to a message the assistant never saw', ChatConversation::where('channel', 'telegram')->sole()->sales_state['context_unknown']);
    }

    public function test_a_reply_to_a_recorded_message_is_answered(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $this->botUpdate(10, 'Salom');
        $this->botUpdate(11, 'Ha', ['reply_to_message' => ['message_id' => 900, 'text' => 'Qanday biznesingiz bor?']]); // our own reply
        $this->assertSame(['turn', 'turn'], array_column($this->calls, 'kind'));
    }

    public function test_claude_saying_the_context_is_unknown_silences_the_session(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $this->turn = ['reply' => 'should not be sent', 'context_unknown' => true];
        $this->botUpdate(1, 'Kecha aytganingizdek 50 ta');
        $this->turn = [];
        $this->botUpdate(2, 'Xoʻsh?');
        $this->assertCount(1, $this->calls);
        $this->assertSame([], $this->sentTo('7001'));
        $this->assertStringContainsString('context_unknown', SalesAssistant::CONTRACT);
    }

    public function test_premium_chats_can_be_left_out_of_the_greeting_and_the_fixed_text_is_the_fallback(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        SalesAssistantSetting::current()->update(['greeting_channels' => ['bot'], 'wait_minutes' => 0]);
        $this->businessUpdate(1, 'Salom');
        $this->assertSame([], $this->sentTo('7002'), 'no greeting in the Premium account');
        $this->assertCount(1, $this->sentTo('-100555'), 'still asked to assign');
        $this->assertSame([], $this->calls);

        $this->fail = true;
        $this->botUpdate(2, 'Salom');
        $text = $this->sentTo('7001')[0]['text'] ?? '';
        $this->assertStringContainsString('Assalomu alaykum', $text, 'language unknown: Uzbek…');
        $this->assertStringContainsString('Здравствуйте', $text, '…and Russian');
    }

    public function test_assignment_off_talks_with_everyone(): void
    {
        SalesAssistantSetting::current()->update(['require_assignment' => false]);
        $this->botUpdate(1, 'Salom');
        $this->assertSame(['turn'], array_column($this->calls, 'kind'));
        $this->assertSame([], $this->sentTo('-100555'));
    }

    public function test_the_assigned_agent_answers_the_client(): void
    {
        $agent = AiEmployee::create(['name' => 'Nodira', 'role' => 'Service desk', 'kind' => 'service', 'status' => 'shadow']);
        $agent->prompts()->create(['version' => 1, 'prompt' => 'You are Nodira.', 'status' => 'published', 'published_at' => now()]);
        $user = TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'ai_employee_id' => $agent->id, 'source' => 'bot']);
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'telegram', 'external_id' => '7001', 'messages' => []]);
        $this->assertSame('Nodira', SalesAssistant::answerer($c)?->name);

        $agent->update(['status' => 'draft']);
        $this->assertNotSame('Nodira', SalesAssistant::answerer($c->fresh())?->name, 'a draft agent never answers');
        $this->assertSame($agent->id, $user->fresh()->ai_employee_id);
    }

    public function test_admin_sets_type_role_and_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = TelegramUser::create(['telegram_id' => '7001', 'source' => 'bot']);

        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'employee', 'employee_role' => 'Hacker'])
            ->assertSessionHasErrors('employee_role');
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'employee', 'employee_role' => 'Finance'])->assertRedirect();
        $user->refresh();
        $this->assertSame(['employee', 'Finance', true], [$user->contact_type, $user->employee_role, $user->is_employee]);

        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'client', 'employee_role' => 'Finance'])->assertRedirect();
        $user->refresh();
        $this->assertSame(['client', null, false], [$user->contact_type, $user->employee_role, $user->is_employee]);

        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()->assertSee('Assignment — who this contact is');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => 'clients']))->assertOk()->assertSee('Not assigned');

        $s = SalesAssistantSetting::current();
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), [
            'bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 4, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => $s->model, 'off_hours_mode' => 'note',
            'require_assignment' => '0', 'greeting_section' => '1', 'greeting_prompt' => "Greet them warmly.\r\nPresent BDB.", 'employee_roles' => "Sales\nCEO\nSales",
        ])->assertRedirect();
        $s->refresh();
        $this->assertFalse($s->requiresAssignment());
        $this->assertSame([], $s->greetingChannels(), 'all unticked');
        $this->assertSame("Greet them warmly.\nPresent BDB.", $s->greeting_prompt);
        $this->assertSame(['Sales', 'CEO'], $s->employeeRoles());
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('Assignment &amp; greeting', false);
    }
}
