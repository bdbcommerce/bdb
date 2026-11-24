<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use App\Services\SalesReport\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Logic review of the contact-type gate and the off-hours note: where they disagree with reminders, follow-ups,
 * staff replies, Premium-account registration, the employee list and the sales report.
 */
class SalesAssistantAssignmentLogicTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    /** @var list<string> */
    public array $calls = [];

    private int $nextId = 900;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test', 'app.url' => 'https://bdb.test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on', 'require_assignment' => true, 'off_hours_mode' => 'note']);
        // Every sent message gets its own Telegram ID (one closure: the first matching fake wins).
        Http::fake(function () {
            return Http::response(['ok' => true, 'result' => ['message_id' => $this->nextId++]]);
        });
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent')); // Tuesday, working hours

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantAssignmentLogicTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $greeting = count($schema['required']) === 3;
                $this->test->calls[] = $greeting ? 'greeting' : 'turn';
                $answer = $greeting
                    ? ['reply' => 'Assalomu alaykum! BDB.', 'language' => 'uz', 'context_unknown' => false]
                    : ['reply' => 'Telefon raqamingizni yuboring.', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'contacts',
                        'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                        'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false,
                        'hand_to' => '', 'context_unknown' => false];

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
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function conversation(array $messages, array $state = [], string $id = '7001'): ChatConversation
    {
        return ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'telegram', 'external_id' => $id,
            'messages' => $messages, 'message_count' => count($messages), 'sales_state' => $state ?: null]);
    }

    private function at(string $time): string
    {
        return Carbon::parse($time, 'Asia/Tashkent')->toIso8601String();
    }

    /** @return list<array> */
    private function sentTo(string $chatId): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === $chatId)->values()->all();
    }

    /**
     * CONFLICT: a staff reply from the admin panel in the bot chat is stored without its Telegram message_id
     * (ChatConversation::appendAdminMessage), so a customer who replies to (quotes) it looks like "a reply to a
     * message the assistant never saw" and the whole session is marked context_unknown.
     */
    public function test_quoting_a_staff_reply_does_not_silence_the_assistant_for_the_session(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $this->botUpdate(1, 'Salom, printer kerak');
        $this->assertSame(['turn'], $this->calls);

        $admin = User::factory()->create(['is_admin' => true]);
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Narxi 2 mln soʻm'])->assertRedirect();
        $staffId = $this->nextId - 1; // the Telegram ID the staff message really got

        $this->botUpdate(2, 'Rahmat. Yetkazib berish bormi?', ['reply_to_message' => ['message_id' => $staffId]]);
        Carbon::setTestNow(now()->addMinutes(10)); // past the manager's wait
        $this->artisan('sales:assistant-tick')->assertSuccessful();

        $this->assertEmpty(($c->fresh()->sales_state ?? [])['context_unknown'] ?? null, 'the quoted message is our own staff reply');
        $this->assertSame(['turn', 'turn'], $this->calls);
    }

    /** CONFLICT: the reminder (nudge) checks mayWrite but not the contact-type gate — a supplier gets a Claude sales turn. */
    public function test_a_supplier_gets_no_sales_reminder(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'supplier', 'source' => 'bot']);
        $this->conversation([
            ['role' => 'user', 'content' => 'Printer kerak', 'message_id' => 1, 'at' => $this->at('2026-11-24 07:00')],
            ['role' => 'assistant', 'content' => 'Telefon raqamingiz?', 'message_id' => 900, 'at' => $this->at('2026-11-24 07:05'), 'sales' => true],
        ], ['handled' => 2, 'stage' => 'contacts']);

        $this->artisan('sales:assistant-tick')->assertSuccessful();

        $this->assertSame([], $this->calls, 'the AI team talks only with clients');
        $this->assertSame([], $this->sentTo('7001'));
    }

    /** CONFLICT: the agreed follow-up checks enabledFor/mayWrite but not the gate — a contact re-typed as not assigned is followed up. */
    public function test_an_unassigned_contact_gets_no_follow_up(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => null, 'source' => 'bot']);
        $this->conversation([
            ['role' => 'user', 'content' => 'Kerak emas', 'message_id' => 1, 'at' => $this->at('2026-11-10 10:00')],
            ['role' => 'assistant', 'content' => 'Keyinroq yozsak boʻladimi?', 'message_id' => 900, 'at' => $this->at('2026-11-10 10:01'), 'sales' => true],
        ], ['handled' => 2, 'stage' => 'cancelled', 'cancelled_at' => $this->at('2026-11-10 10:00'), 'follow_up_at' => $this->at('2026-11-24 09:00')]);

        $this->artisan('sales:assistant-tick')->assertSuccessful();

        $this->assertSame([], $this->calls, 'no Claude turn for a contact the AI team may not talk to');
        $this->assertSame([], $this->sentTo('7001'));
    }

    /**
     * CONFLICT: the Premium-account flow drops an admin's ask_registration whenever the assistant is "on" for the
     * contact (TelegramRecorder::businessMessage), but for a contact not assigned yet the assistant only greets —
     * nobody collects the details the admin asked for.
     */
    public function test_ask_registration_is_not_lost_for_an_unassigned_business_contact(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        TelegramUser::create(['telegram_id' => '7002', 'source' => 'business', 'ask_registration' => true, 'locale' => 'uz']);
        Carbon::setTestNow(Carbon::parse('2026-11-24 20:00', 'Asia/Tashkent')); // outside hours: no manager wait
        $this->postJson('/telegram/webhook', ['update_id' => 50, 'business_message' => ['text' => 'Salom', 'message_id' => 50, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => 7002, 'first_name' => 'Vali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $user = TelegramUser::where('telegram_id', '7002')->sole();
        $askedName = collect($this->sentTo('7002'))->contains(fn ($m) => $m['text'] === __('chat.tg_biz_ask_name', [], 'uz'));
        $this->assertTrue($user->ask_registration || $askedName, 'the admin\'s request is either kept or carried out');
    }

    /** CONFLICT: employee_ids (settings) wins over the type an admin chose: listed as Client, treated as an employee. */
    public function test_the_contact_type_and_the_employee_list_agree(): void
    {
        SalesAssistantSetting::current()->update(['employee_ids' => '7001']);
        $user = TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $s = SalesAssistantSetting::current();

        $this->assertSame($s->gate($user) === 'talk', $s->enabledFor('telegram', $user, '7001'),
            'a "Client" must get the AI team, or the type should say Employee');
    }

    /**
     * CONFLICT: during an open sales dialogue a customer writes after hours → the note marks the message handled,
     * the next morning the AI does not resume, and because the note is the last message neither the reminder nor
     * the close (unfinished request → manager) ever runs: the collected request never reaches the sales chat.
     */
    public function test_an_open_request_still_reaches_the_managers_after_an_off_hours_note(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $this->conversation([
            ['role' => 'user', 'content' => 'Zebra ZD421 printer kerak, 2 dona', 'message_id' => 1, 'at' => $this->at('2026-11-23 17:00')],
            ['role' => 'assistant', 'content' => 'Telefon raqamingiz?', 'message_id' => 900, 'at' => $this->at('2026-11-23 17:01'), 'sales' => true],
            ['role' => 'user', 'content' => 'Ertaga yozaman', 'message_id' => 2, 'at' => $this->at('2026-11-23 19:00')],
            ['role' => 'assistant', 'content' => 'Ofis yopiq', 'message_id' => 901, 'at' => $this->at('2026-11-23 19:00'), 'sales' => true, 'off_hours' => true],
        ], ['handled' => 4, 'stage' => 'contacts', 'summary' => 'Zebra ZD421 x2', 'items' => [['name' => 'Zebra ZD421', 'qty' => 2]],
            'off_hours_until' => $this->at('2026-11-24 09:00')]);

        Carbon::setTestNow(Carbon::parse('2026-11-24 20:00', 'Asia/Tashkent')); // 25 h later
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00', 'Asia/Tashkent'));
        $this->artisan('sales:assistant-tick')->assertSuccessful();

        $this->assertTrue(ChatLead::exists(), 'the collected request is handed to the managers as unfinished');
    }

    /**
     * Older Premium-account sessions (channel business) keep the connection on the session, not on each message:
     * a customer replying to a message from such a session must still count as a reply to a known message.
     */
    public function test_a_reply_to_a_message_in_an_older_business_session_is_known(): void
    {
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'locale' => 'uz', 'channel' => 'business', 'external_id' => '7002', 'business_connection_id' => 'bc1',
            'messages' => [['role' => 'owner', 'content' => 'Narxi 2 mln', 'message_id' => 500, 'at' => $this->at('2026-11-01 10:00')]], 'message_count' => 1]);
        $new = $this->conversation([
            ['role' => 'user', 'content' => 'Shu narx hali ham amaldami?', 'message_id' => 600, 'reply_to' => 500, 'via' => 'assistant', 'connection' => 'bc1', 'at' => $this->at('2026-11-24 09:59')],
        ], [], '7002');

        $this->assertFalse(SalesAssistant::unknownBeginning($new), 'message 500 of that Premium chat is recorded (older session)');
    }

    /**
     * DESIGN CONFLICT: context_unknown is sticky for the session, and a session only ends after 24 h idle (or 500
     * messages). After a manager has answered, a client who keeps writing every day never gets the AI again.
     */
    public function test_context_unknown_ends_once_a_manager_has_taken_the_chat(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot']);
        $c = $this->conversation([
            ['role' => 'user', 'content' => 'Kechagi buyurtma nima boʻldi?', 'message_id' => 1, 'at' => $this->at('2026-11-23 15:00')],
            ['role' => 'admin', 'content' => 'Ertaga yetkaziladi', 'author' => 'Sardor', 'message_id' => 950, 'at' => $this->at('2026-11-23 15:30')],
            ['role' => 'user', 'content' => 'Yangi savol: Zebra ZD421 bormi?', 'message_id' => 2, 'at' => $this->at('2026-11-24 09:40')],
        ], ['handled' => 1, 'context_unknown' => 'the customer continues a talk the assistant never saw']);

        $this->assertSame('answered', app(SalesAssistant::class)->handle($c), 'a new question the next day, after the manager answered');
    }

    /** CONFLICT: the sales report treats the off-hours note as the AI answering, so the managers' morning reply is not timed. */
    public function test_the_off_hours_note_does_not_hide_the_managers_reply_time(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'source' => 'bot', 'name' => 'Ali']);
        $this->conversation([
            ['role' => 'user', 'content' => 'Printer narxi?', 'message_id' => 1, 'at' => $this->at('2026-11-23 20:00')],
            ['role' => 'assistant', 'content' => 'Ofis yopiq', 'message_id' => 900, 'at' => $this->at('2026-11-23 20:00'), 'sales' => true, 'off_hours' => true],
            ['role' => 'admin', 'content' => 'Salom, 2 mln', 'author' => 'Sardor', 'at' => $this->at('2026-11-24 09:30')],
        ]);

        $data = app(SalesReporter::class)->collect(now()->subDays(7), now(), ['telegram', 'business']);

        $this->assertNotEmpty($data['reply_times'], 'the manager answered a 13.5 h old question: that wait is measured');
        $this->assertStringNotContainsString('no replies measured', $data['text']);
    }
}
