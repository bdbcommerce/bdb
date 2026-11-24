<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\ChatConversation;
use App\Models\ChatLead;
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

/** QA edge cases for contact types, the greeting and the reply_to check. */
class SalesAssistantAssignmentQaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $greeting = [];

    public array $turn = [];

    public bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test', 'app.url' => 'https://bdb.test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on', 'require_assignment' => true, 'wait_minutes' => 5]);
        $id = 899;
        Http::fake(['api.telegram.org/*' => function () use (&$id) {
            return Http::response(['ok' => true, 'result' => ['message_id' => ++$id]]);
        }]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent')); // Tuesday, working hours

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantAssignmentQaTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $greeting = count($schema['required']) === 3;
                $this->test->calls[] = ['kind' => $greeting ? 'greeting' : 'turn', 'system' => $system, 'data' => $data];
                if ($this->test->fail) {
                    throw new RuntimeException('overloaded');
                }
                $answer = $greeting
                    ? $this->test->greeting + ['reply' => 'Assalomu alaykum! BDB. Menejer tez orada bogʻlanadi.', 'language' => 'uz', 'context_unknown' => false]
                    : $this->test->turn + ['reply' => 'Qanday biznesingiz bor?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
                        'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                        'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'hand_to' => '', 'context_unknown' => false];

                return ['text' => json_encode($answer), 'model' => $model, 'input_tokens' => 10, 'output_tokens' => 10];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function botUpdate(int $id, array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali', 'username' => 'ali_uz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, string $text, array $extra = [], int $from = 7002): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => $extra + ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => $from === 7002 ? 'Vali' : 'Aziz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function sentTo(string $chatId): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === $chatId)->values()->all();
    }

    private function connection(bool $canReply = true): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => $canReply]);
    }

    public function test_premium_greeting_when_can_reply_is_false(): void
    {
        $this->connection(false);
        SalesAssistantSetting::current()->update(['wait_minutes' => 0]);
        $this->businessUpdate(1, 'Salom');
        $this->assertSame([], $this->calls, 'no Claude call');
        $this->assertSame([], $this->sentTo('7002'), 'nothing sent to the customer');
        $user = TelegramUser::where('telegram_id', '7002')->sole();
        $this->assertNull($user->greeted_at);
        // Observed: is the sales chat asked to assign this contact?
        $this->assertCount(1, $this->sentTo('-100555'), 'sales chat should still be asked to assign a new Premium contact');
    }

    public function test_greeted_contact_later_assigned_as_client_gets_a_turn_with_the_greeting_in_context(): void
    {
        $this->botUpdate(1, ['text' => 'Salom, etiketka kerak']);
        $this->botUpdate(2, ['text' => 'Narxi?']);
        $this->assertSame(['greeting'], array_column($this->calls, 'kind'));
        TelegramUser::where('telegram_id', '7001')->update(['contact_type' => 'client']);
        $this->botUpdate(3, ['text' => 'Tarozi uchun']);
        $this->assertSame(['greeting', 'turn'], array_column($this->calls, 'kind'));
        $this->assertStringContainsString('Tarozi uchun', $this->calls[1]['data']);
        $this->assertCount(2, $this->sentTo('7001'));
    }

    public function test_unassigned_outside_hours_gets_greeting_only_and_no_note(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 21:00', 'Asia/Tashkent'));
        SalesAssistantSetting::current()->update(['off_hours_mode' => 'note']);
        $this->fail = true; // fixed fallback text
        $this->botUpdate(1, ['text' => 'Salom']);
        $this->botUpdate(2, ['text' => 'Bormisiz?']);
        $sent = $this->sentTo('7001');
        $this->assertCount(1, $sent, 'one message only');
        $this->assertStringContainsString('09:00', $sent[0]['text'], 'working hours in the greeting');
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertFalse(collect($c->messages)->contains(fn ($m) => ! empty($m['off_hours'])));
        $this->assertTrue($c->awaiting_reply);

        // Assigned as a client while still closed: the next message gets the note.
        TelegramUser::where('telegram_id', '7001')->update(['contact_type' => 'client']);
        $this->botUpdate(3, ['text' => 'Yana savol']);
        $c->refresh();
        $this->assertTrue(collect($c->messages)->contains(fn ($m) => ! empty($m['off_hours'])));
    }

    public function test_voice_from_unassigned_contact_is_forwarded_and_greeted_once(): void
    {
        $this->botUpdate(1, ['voice' => ['file_id' => 'v1', 'duration' => 5]]);
        $forwards = Http::recorded(fn (Request $r) => str_contains($r->url(), '/forwardMessage'))->count();
        $this->assertSame(1, $forwards, 'voice forwarded');
        $this->assertCount(1, $this->calls);
        $this->assertStringContainsString('[voice]', $this->calls[0]['data']);
        $toSales = collect($this->sentTo('-100555'))->pluck('text')->implode("\n---\n");
        $this->assertStringContainsString('New contact — please assign', $toSales);
        $this->assertStringContainsString('Wrote:</b> [voice]', $toSales);
    }

    public function test_client_replying_to_a_manager_message_sent_from_the_admin_panel_is_answered(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        SalesAssistantSetting::current()->update(['wait_minutes' => 0]);
        $this->botUpdate(1, ['text' => 'Salom']);
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Qaysi model kerak?'])->assertRedirect();
        $this->assertSame(['turn'], array_column($this->calls, 'kind'));

        // Telegram gave the AI reply 900 and the manager's message 901; the customer swipes-replies to it.
        $this->botUpdate(2, ['text' => 'Zebra ZD421', 'reply_to_message' => ['message_id' => 901, 'text' => 'Qaysi model kerak?']]);
        $c->refresh();
        $this->assertEmpty($c->sales_state['context_unknown'] ?? null, 'a reply to our own manager is not an unknown beginning');
    }

    public function test_reply_to_a_message_in_an_older_business_session_of_the_same_chat(): void
    {
        $this->connection();
        SalesAssistantSetting::current()->update(['wait_minutes' => 0]);
        TelegramUser::create(['telegram_id' => '7002', 'contact_type' => 'client', 'source' => 'business']);
        $old = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'business', 'external_id' => '7002', 'business_connection_id' => 'bc1',
            'messages' => [
                ['role' => 'user', 'content' => 'Printer kerak', 'message_id' => 40, 'at' => now()->subDays(3)->toIso8601String()],
                ['role' => 'owner', 'content' => 'Taklif yubordim', 'message_id' => 41, 'at' => now()->subDays(3)->toIso8601String()],
            ]]);
        $old->timestamps = false;
        $old->forceFill(['updated_at' => now()->subDays(3), 'created_at' => now()->subDays(3)])->save();

        $this->businessUpdate(50, 'Rahmat, qabul qildim', ['reply_to_message' => ['message_id' => 41, 'text' => 'Taklif yubordim']]);
        $new = ChatConversation::where('channel', 'telegram')->where('external_id', '7002')->first();
        $this->assertNotNull($new, 'a new session');
        $this->assertFalse(SalesAssistant::unknownBeginning($new), 'message 41 is recorded in the older session of the same Premium chat');
    }

    public function test_reply_to_a_message_in_an_older_telegram_session_is_known(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $old = ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'telegram', 'external_id' => '7001',
            'messages' => [['role' => 'user', 'content' => 'Printer kerak', 'message_id' => 40, 'at' => now()->subDays(3)->toIso8601String()],
                ['role' => 'assistant', 'content' => 'Qanday?', 'message_id' => 41, 'sales' => true, 'at' => now()->subDays(3)->toIso8601String()]]]);
        $old->timestamps = false;
        $old->forceFill(['updated_at' => now()->subDays(3), 'created_at' => now()->subDays(3)])->save();
        $this->botUpdate(60, ['text' => 'Ha, 2 dona', 'reply_to_message' => ['message_id' => 41]]);
        $new = ChatConversation::where('channel', 'telegram')->where('id', '!=', $old->id)->first();
        $this->assertNotNull($new);
        $this->assertFalse(SalesAssistant::unknownBeginning($new));
        $this->assertSame(['turn'], array_column($this->calls, 'kind'));
    }

    public function test_unassigned_premium_contact_already_talking_with_the_owner_is_not_greeted(): void
    {
        $this->connection();
        // The owner starts the chat, the customer answers, nobody answers within wait_minutes.
        $this->businessUpdate(1, 'Assalomu alaykum, hisob-faktura tayyor', from: 9000);
        $this->businessUpdate(2, 'Rahmat, qachon olib ketsam boʻladi?');
        $this->travel(10)->minutes();
        $this->artisan('sales:assistant-tick')->assertOk();
        $this->assertSame([], $this->sentTo('7002'), 'a greeting "a manager will contact you shortly" after the owner has been talking');
    }

    public function test_settings_validation_old_input_and_prompt_reset(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $s = SalesAssistantSetting::current();
        $base = ['bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 4, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => $s->model, 'off_hours_mode' => 'note', 'greeting_section' => '1'];

        $this->actingAs($admin)->from(route('admin.sales-assistant.edit'))->put(route('admin.sales-assistant.update'), $base + [
            'greeting_channels' => ['bot', 'evil'], 'greeting_prompt' => str_repeat('a', 8001), 'require_assignment' => 'maybe', 'employee_roles' => 'Sales',
        ])->assertRedirect(route('admin.sales-assistant.edit'))->assertSessionHasErrors(['greeting_channels.1', 'greeting_prompt', 'require_assignment'])->assertSessionHasInput('employee_roles', 'Sales');
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk();

        // Prompt cleared → default; prompt equal to the default (CRLF) → stored null.
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $base + ['greeting_prompt' => '', 'greeting_channels' => ['business']])->assertSessionHasNoErrors();
        $s->refresh();
        $this->assertNull($s->greeting_prompt);
        $this->assertSame(['business'], $s->greetingChannels());
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $base + ['greeting_prompt' => str_replace("\n", "\r\n", SalesAssistantSetting::defaultGreetingPrompt())])->assertSessionHasNoErrors();
        $this->assertNull($s->fresh()->greeting_prompt);
        $this->assertSame([], $s->fresh()->greetingChannels(), 'no channels ticked = none');
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('first-contact assistant');

        // Cyrillic custom prompt and roles.
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $base + ['greeting_prompt' => 'Поздоровайся тепло. Oʻzbekcha ham.', 'employee_roles' => "Бухгалтер\r\n  \r\nSotuvchi"])->assertSessionHasNoErrors();
        $this->assertSame(['Бухгалтер', 'Sotuvchi'], $s->fresh()->employeeRoles());
    }

    public function test_admin_pages_access_and_user_page_states(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7001', 'source' => 'bot', 'phone' => '+998901234567', 'name' => 'Ali']);
        ChatLead::create(['name' => 'Ali', 'phone' => '+998901234567', 'channel' => 'telegram', 'locale' => 'uz']);

        $this->get(route('admin.telegram-users.show', $user))->assertRedirect();
        $this->get(route('admin.sales-assistant.edit'))->assertRedirect();
        $plain = User::factory()->create(['is_admin' => false]);
        $this->assertContains($this->actingAs($plain)->get(route('admin.telegram-users.show', $user))->status(), [302, 403]);
        $this->assertContains($this->actingAs($plain)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'client'])->status(), [302, 403]);
        $this->assertNull($user->fresh()->contact_type);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()
            ->assertSee('Not assigned yet')->assertSee('a request with the same phone exists')->assertSee('Use it');
        foreach (['unassigned', 'clients', 'suppliers', 'employees', 'all'] as $f) {
            $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => $f]))->assertOk();
        }
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => 'unassigned']))->assertSee('Ali');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => 'clients']))->assertDontSee('+998901234567');

        // Invalid type / non-runnable agent.
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'boss'])->assertSessionHasErrors('contact_type');
        $mock = AiEmployee::create(['name' => 'Analyst', 'role' => 'x', 'kind' => 'analyst', 'status' => 'draft']);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'client', 'ai_employee_id' => $mock->id])->assertSessionHasErrors('ai_employee_id');

        // Supplier → type kept when the type select is sent empty? (Not assigned.)
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => 'supplier'])->assertSessionHasNoErrors();
        $this->assertSame('supplier', $user->fresh()->contact_type);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['contact_type' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->contact_type);
        $this->assertNull($user->fresh()->assigned_at);
        // Legacy tick alone.
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['is_employee' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('employee', $user->fresh()->contact_type);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['is_employee' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('client', $user->fresh()->contact_type);
    }
}
