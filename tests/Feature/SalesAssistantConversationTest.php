<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The sales assistant in the bot chat and in Premium-account chats; Claude is faked, Telegram is Http::fake. */
class SalesAssistantConversationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    private const GROUP = '-1003333333333';

    /** @var list<array> what the fake Claude got */
    public array $calls = [];

    /** @var list<array> answers, one per turn (the last one repeats) */
    public array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => self::GROUP]);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantConversationTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('model', 'system', 'data', 'images');
                $answer = count($this->test->answers) > 1 ? array_shift($this->test->answers) : ($this->test->answers[0] ?? []);

                return ['text' => json_encode($answer), 'model' => $model, 'input_tokens' => 1000, 'output_tokens' => 100];
            }
        });
    }

    public function answer(array $override = []): array
    {
        return $override + ['reply' => 'Assalomu alaykum! Qanday biznesingiz bor?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false];
    }

    private function botUpdate(int $id, array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali', 'username' => 'ali_uz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, array $message, int $from = 7002): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => $from === 7002 ? 'Vali' : 'Owner']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<array> sendMessage calls */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    public function test_bot_chat_runs_the_conversation_and_hands_the_confirmed_request_to_the_sales_chat(): void
    {
        $this->answers = [
            $this->answer(),
            $this->answer(['reply' => 'Telefon raqamingizni yuboring', 'ask_phone_button' => true, 'stage' => 'contacts']),
            $this->answer(['reply' => 'Etiketka 58×40, oyiga 20 rulon; Ali Karimov, Toshkent. Toʻgʻrimi?', 'stage' => 'confirm', 'business_type' => 'Retail shop',
                'customer' => ['first_name' => 'Ali', 'last_name' => 'Karimov', 'phone' => '', 'city' => 'Toshkent', 'company' => 'Mega <b>'],
                'items' => [['product' => 'Labels', 'details' => '58×40 thermal, 20 rolls/month']], 'summary' => 'Labels 58×40 thermal, 20 rolls a month, for scales.']),
            $this->answer(['reply' => 'Rahmat! Menejerimiz tez orada javob beradi.', 'stage' => 'handed_over', 'confirmed' => true,
                'customer' => ['first_name' => 'Ali', 'last_name' => 'Karimov', 'phone' => '', 'city' => 'Toshkent', 'company' => 'Mega <b>'],
                'items' => [['product' => 'Labels', 'details' => '58×40 thermal, 20 rolls/month']], 'summary' => 'Labels 58×40 thermal, 20 rolls a month, for scales.']),
        ];

        $this->botUpdate(1, ['text' => 'Salom, etiketka kerak']);
        $this->botUpdate(2, ['text' => 'Tarozi uchun 58×40']);
        $this->botUpdate(3, ['contact' => ['phone_number' => '998901234567', 'user_id' => 7001]]);
        $this->botUpdate(4, ['text' => 'Ha, toʻgʻri']);

        $this->assertCount(4, $this->calls);
        $this->assertStringContainsString('"from":"Customer","text":"Salom, etiketka kerak"', $this->calls[0]['data']);
        $this->assertStringContainsString('Customer: new', $this->calls[0]['data']);
        $this->assertStringContainsString('# WEBSITE DATA', $this->calls[0]['system']);
        $this->assertStringContainsString('# YOUR ANSWER (JSON)', $this->calls[0]['system']);
        $this->assertStringContainsString('"from":"You (assistant)","text":"Assalomu alaykum!', $this->calls[1]['data']);
        $this->assertStringContainsString('[sent a contact card', $this->calls[2]['data']);

        $toCustomer = array_values(array_filter($this->sent(), fn ($m) => (string) $m['chat_id'] === '7001'));
        $this->assertSame('Assalomu alaykum! Qanday biznesingiz bor?', $toCustomer[0]['text']);
        $this->assertTrue($toCustomer[1]['reply_markup']['keyboard'][0][0]['request_contact']);

        $user = TelegramUser::where('telegram_id', '7001')->sole();
        $this->assertSame(['Ali Karimov', '+998901234567', 'Mega <b>'], [$user->name, $user->phone, $user->company]);
        $this->assertNotNull($user->phone_verified_at, 'shared with the button');

        $lead = ChatLead::sole();
        $this->assertSame(['telegram', 'Ali Karimov', '+998901234567'], [$lead->channel, $lead->name, $lead->phone]);
        $toSales = array_values(array_filter($this->sent(), fn ($m) => (string) $m['chat_id'] === '-100555'));
        $this->assertCount(1, $toSales);
        $this->assertStringContainsString('New request — Telegram bot', $toSales[0]['text']);
        $this->assertStringContainsString('Mega &lt;b&gt;', $toSales[0]['text']);
        $this->assertStringContainsString('• Labels: 58×40 thermal', $toSales[0]['text']);

        $conversation = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertSame(['user', 'assistant'], array_values(array_unique(array_column($conversation->messages, 'role'))));
        $this->assertSame($lead->id, $conversation->sales_state['lead_id']);

        // The same confirmation again does not make a second lead.
        $this->answers = [$this->answers[0] ?? $this->answer(['reply' => 'Arzimaydi!', 'confirmed' => true, 'summary' => 'Labels 58×40 thermal, 20 rolls a month, for scales.'])];
        $this->botUpdate(5, ['text' => 'Rahmat']);
        $this->assertSame(1, ChatLead::count());
    }

    public function test_unknown_question_is_silent_to_the_customer_and_goes_to_the_source_group_once(): void
    {
        $this->answers = [$this->answer(['reply' => '', 'unknown_question' => 'Does the XYZ-900 print on <foil>?'])];
        $this->botUpdate(1, ['text' => 'XYZ-900 folga chop etadimi?']);
        $this->botUpdate(2, ['text' => '???']);

        $this->assertSame([], array_values(array_filter($this->sent(), fn ($m) => (string) $m['chat_id'] === '7001')), 'silent');
        $toGroup = array_values(array_filter($this->sent(), fn ($m) => (string) $m['chat_id'] === self::GROUP));
        $this->assertCount(1, $toGroup, 'the same question once');
        $this->assertStringContainsString('&lt;foil&gt;', $toGroup[0]['text']);
        $this->assertStringContainsString('Open the chat', $toGroup[0]['text']);

        // A silent turn is never asked again by the every-minute check.
        $calls = count($this->calls);
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount($calls, $this->calls);
    }

    public function test_employees_get_nothing_and_switched_off_falls_back_to_recording(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'is_employee' => true]);
        $this->answers = [$this->answer()];
        $this->botUpdate(1, ['text' => 'Salom']);
        $this->assertSame([], $this->calls);

        TelegramUser::where('telegram_id', '7001')->update(['is_employee' => false]);
        SalesAssistantSetting::current()->update(['bot_mode' => 'off']);
        $this->botUpdate(2, ['text' => 'Salom']);
        $this->assertSame([], $this->calls);
        $this->assertSame(2, count(ChatConversation::where('channel', 'telegram')->sole()->messages), 'still recorded');
    }

    public function test_a_failed_turn_is_not_retried_every_minute_and_alerts(): void
    {
        $this->app->instance(SalesAssistantAi::class, new class extends SalesAssistantAi
        {
            public int $n = 0;

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->n++;
                throw new \RuntimeException('Claude API error 529: overloaded sk-ant-test');
            }
        });
        $this->botUpdate(1, ['text' => 'Salom']);
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertSame(1, app(SalesAssistantAi::class)->n);
        $alert = collect($this->sent())->first(fn ($m) => str_contains($m['text'], 'Sales assistant'));
        $this->assertNotNull($alert);
        $this->assertStringNotContainsString('sk-ant-test', $alert['text']);
    }

    public function test_business_chat_waits_for_a_manager_in_working_hours_then_answers_as_the_account(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent')); // Monday
        $this->answers = [$this->answer(['reply' => 'Salom! Sizga qanday yordam bera olaman?'])];

        $this->businessUpdate(1, ['text' => 'Salom, skaner kerak']);
        $this->assertSame([], $this->calls, 'a manager gets 5 minutes first');

        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('sales:assistant-tick');
        $this->assertSame([], $this->calls);

        Carbon::setTestNow(now()->addMinutes(3));
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->calls);
        $reply = collect($this->sent())->first(fn ($m) => ($m['business_connection_id'] ?? null) === 'bc1');
        $this->assertSame('Salom! Sizga qanday yordam bera olaman?', $reply['text']);

        // The assistant is now talking: the next customer message is answered at once.
        $this->businessUpdate(2, ['text' => 'Simsiz']);
        $this->assertCount(2, $this->calls);

        // The manager writes: the assistant stops; the customer answers; it waits again.
        $this->businessUpdate(3, ['text' => 'Men Aziz, hozir yordam beraman'], from: 9000);
        $this->businessUpdate(4, ['text' => 'Yaxshi']);
        $this->assertCount(2, $this->calls);
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('sales:assistant-tick');
        $this->assertCount(3, $this->calls);
        $this->assertStringContainsString('"from":"Manager (Premium account)","text":"Men Aziz', $this->calls[2]['data']);
        Carbon::setTestNow();
    }

    public function test_business_chat_outside_working_hours_answers_at_once_and_needs_can_reply(): void
    {
        $connection = TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => false]);
        Carbon::setTestNow(Carbon::parse('2026-11-22 21:00', 'Asia/Tashkent')); // Sunday evening
        $this->answers = [$this->answer()];

        $this->businessUpdate(1, ['text' => 'Salom']);
        $this->assertSame([], $this->calls, 'the bot may not reply in this account');

        $connection->update(['can_reply' => true]);
        $this->businessUpdate(2, ['text' => 'Salom?']);
        $this->assertCount(1, $this->calls);
        $this->assertStringContainsString('outside working hours', $this->calls[0]['data']);
        Carbon::setTestNow();
    }

    public function test_decision_rules(): void
    {
        $s = SalesAssistantSetting::current();
        $at = Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent');
        $c = fn (string $channel, array $roles, array $state = []) => new ChatConversation(['channel' => $channel, 'sales_state' => $state,
            'messages' => array_map(fn ($r) => ['role' => $r, 'content' => 'x', 'at' => $at->copy()->subMinutes(10)->toIso8601String()], $roles)]);

        $this->assertSame('no', SalesAssistant::decision($c('telegram', ['user', 'assistant']), $s, $at));
        $this->assertSame('now', SalesAssistant::decision($c('telegram', ['user']), $s, $at));
        $this->assertSame('now', SalesAssistant::decision($c('business', ['user']), $s, $at), 'waited 10 minutes');
        $this->assertSame('wait', SalesAssistant::decision($c('business', ['user']), $s, $at->copy()->subMinutes(8)));
        $this->assertSame('now', SalesAssistant::decision($c('business', ['user', 'bot', 'user']), $s, $at->copy()->subMinutes(9)));
        $this->assertSame('wait', SalesAssistant::decision($c('telegram', ['user', 'admin', 'user']), $s, $at->copy()->subMinutes(8)));
        $this->assertSame('no', SalesAssistant::decision($c('telegram', ['user'], ['handled' => 1]), $s, $at), 'already seen');
    }
}
