<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Outside working hours: the working-hours note once per closed period, repeat messages ignored. */
class SalesAssistantOffHoursTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public int $claudeCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on', 'off_hours_mode' => 'note']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantOffHoursTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->claudeCalls++;

                return ['text' => json_encode(['reply' => 'Assalomu alaykum!', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false]),
                    'model' => $model, 'input_tokens' => 10, 'output_tokens' => 10];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Tashkent'));
    }

    private function botUpdate(int $id, string $text, ?string $languageCode = null): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali'] + ($languageCode ? ['language_code' => $languageCode] : [])]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, string $text, int $from = 7002): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => $from === 7002 ? 'Vali' : 'Owner']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<array> sendMessage calls to the customer */
    private function toCustomer(string $chatId = '7001'): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === $chatId)->values()->all();
    }

    public function test_note_once_then_repeats_ignored_and_the_chat_waits_for_a_manager(): void
    {
        $this->at('2026-11-24 20:00'); // Tuesday evening

        $this->botUpdate(1, 'Salom, etiketka kerak');
        $this->botUpdate(2, 'Bormisiz?');
        $this->botUpdate(3, '???');

        $this->assertSame(0, $this->claudeCalls, 'no AI answer outside working hours');
        $sent = $this->toCustomer();
        $this->assertCount(1, $sent, 'one note, repeats ignored');
        // Language not known yet: Uzbek and Russian.
        $this->assertStringContainsString('Du–Ju 09:00–18:00', $sent[0]['text'] ?? '');
        $this->assertStringContainsString('менеджер ответит завтра с 09:00', $sent[0]['text'] ?? '');

        $c = ChatConversation::where('channel', 'telegram')->sole();
        $note = collect($c->messages)->firstWhere('off_hours', true);
        $this->assertSame('assistant', $note['role']);
        $this->assertTrue($c->awaiting_reply, 'the note is not an answer');
        $this->assertSame(4, $c->sales_state['handled']);
        $this->assertSame('2026-11-25 09:00', Carbon::parse($c->sales_state['off_hours_until'])->setTimezone('Asia/Tashkent')->format('Y-m-d H:i'));

        // The tick at night does nothing more.
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->toCustomer());

        // Morning: the night messages are left to the managers; a new message is answered by the assistant.
        $this->at('2026-11-25 10:00');
        $this->artisan('sales:assistant-tick');
        $this->assertSame(0, $this->claudeCalls);
        $this->botUpdate(4, 'Salom yana');
        $this->assertSame(1, $this->claudeCalls);

        // The next evening: the note again (a new closed period).
        $this->at('2026-11-25 19:00');
        $this->botUpdate(5, 'Yana bir savol');
        $this->assertCount(3, $this->toCustomer());
        $this->assertSame(1, $this->claudeCalls);
    }

    public function test_note_in_the_customers_language_names_the_next_working_day(): void
    {
        $this->at('2026-11-27 19:30'); // Friday evening: open again on Monday
        $this->botUpdate(1, 'Здравствуйте', 'ru');

        $text = $this->toCustomer()[0]['text'] ?? '';
        $this->assertStringContainsString('Пн–Пт 09:00–18:00', $text);
        $this->assertStringContainsString('в понедельник с 09:00', $text);

        $s = SalesAssistantSetting::current();
        $this->assertStringContainsString('tomorrow from 09:00', $s->offHoursNote('en', Carbon::parse('2026-11-24 20:00', 'Asia/Tashkent')));
        $this->assertStringContainsString('today from 09:00', $s->offHoursNote('en', Carbon::parse('2026-11-24 07:00', 'Asia/Tashkent')));
        $s->update(['work_days' => [1, 2, 3, 4, 5, 6]]);
        $this->assertSame('Du–Sh 09:00–18:00', $s->fresh()->hoursText('uz'));
        $s->update(['work_days' => [1, 3]]);
        $this->assertSame('Mon, Wed 09:00–18:00', $s->fresh()->hoursText('en'));
    }

    public function test_silent_mode_sends_nothing_and_ai_mode_answers_as_before(): void
    {
        $this->at('2026-11-24 20:00');
        SalesAssistantSetting::current()->update(['off_hours_mode' => 'silent']);
        $this->botUpdate(1, 'Salom');
        $this->assertSame([], $this->toCustomer());
        $this->assertSame(0, $this->claudeCalls);

        SalesAssistantSetting::current()->update(['off_hours_mode' => 'ai']);
        $this->botUpdate(2, 'Salom?');
        $this->assertSame(1, $this->claudeCalls);
    }

    public function test_business_chat_gets_the_note_on_behalf_of_the_account_unless_the_owner_is_talking(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $this->at('2026-11-24 20:00');

        $this->businessUpdate(1, 'Salom');
        $this->businessUpdate(2, 'Salom?');
        $sent = $this->toCustomer('7002');
        $this->assertCount(1, $sent);
        $this->assertSame('bc1', $sent[0]['business_connection_id']);
        $this->assertSame(0, $this->claudeCalls);

        // The next night the owner answers himself: no note in the middle of his talk.
        $this->at('2026-11-25 21:00');
        $this->businessUpdate(3, 'Hozir javob beraman', from: 9000);
        $this->businessUpdate(4, 'Rahmat');
        $this->assertCount(1, $this->toCustomer('7002'));
    }

    public function test_a_premium_wait_running_past_closing_time_gets_the_note_from_the_tick(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $this->at('2026-11-24 17:58'); // working hours: the manager gets wait_minutes first
        $this->businessUpdate(1, 'Salom');
        $this->assertSame([], $this->toCustomer('7002'));

        $this->at('2026-11-24 18:10');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->toCustomer('7002'));
        $this->assertSame(0, $this->claudeCalls);
    }

    public function test_decision_is_closed_outside_working_hours(): void
    {
        $s = SalesAssistantSetting::current();
        $at = Carbon::parse('2026-11-24 22:00', 'Asia/Tashkent');
        $c = new ChatConversation(['channel' => 'telegram', 'sales_state' => [], 'messages' => [['role' => 'user', 'content' => 'x', 'at' => $at->copy()->subMinute()->toIso8601String()]]]);
        $this->assertSame('closed', SalesAssistant::decision($c, $s, $at));
        $this->assertSame('now', SalesAssistant::decision($c, $s, Carbon::parse('2026-11-25 09:30', 'Asia/Tashkent')));
    }
}
