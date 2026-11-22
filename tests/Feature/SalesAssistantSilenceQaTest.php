<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA edge cases of the silence handling: reminder, unfinished request, follow-up. */
class SalesAssistantSilenceQaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answers = [];

    public ?\Closure $duringTurn = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        $n = 900;
        Http::fake(['api.telegram.org/*' => function () use (&$n) {
            return Http::response(['ok' => true, 'result' => ['message_id' => $n++]]);
        }]);

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantSilenceQaTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;
                if ($fn = $this->test->duringTurn) {
                    $this->test->duringTurn = null;
                    $fn();
                }
                $answer = count($this->test->answers) > 1 ? array_shift($this->test->answers) : ($this->test->answers[0] ?? []);

                return ['text' => json_encode($answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function answer(array $override = []): array
    {
        return $override + ['reply' => 'Qaysi oʻlchamdagi etiketka kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'questions',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => 'shop', 'software' => '',
            'items' => [['product' => 'Labels', 'details' => 'for scales']], 'summary' => 'Labels for scales, size not given yet.', 'confirmed' => false,
            'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false];
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Tashkent'));
    }

    private function botUpdate(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => 7002, 'first_name' => 'Vali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function connection(bool $canReply = true): TelegramBusinessConnection
    {
        return TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => $canReply]);
    }

    private function sentTo(string $chat): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r->data()['chat_id'] ?? '') === $chat)
            ->map(fn ($p) => $p[0]->data())->values()->all();
    }

    private function bot(): ChatConversation
    {
        return ChatConversation::where('channel', 'telegram')->sole();
    }

    /** A customer message stored while the reminder turn runs must still get an answer. */
    public function test_customer_message_during_the_reminder_turn_is_answered(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Tarozi uchun etiketka kerak');

        $this->at('2026-11-23 12:05');
        $this->duringTurn = function () {
            $c = ChatConversation::where('channel', 'telegram')->sole(); // stored while Claude writes the reminder
            $c->messages = [...$c->messages, ['role' => 'user', 'content' => '58x40 kerak', 'message_id' => 2, 'at' => now()->toIso8601String()]];
            $c->save();
        };
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(2, $this->calls, 'reminder turn only');

        $this->at('2026-11-23 12:06');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $roles = array_column($this->bot()->messages, 'role');
        $this->assertCount(3, $this->calls, 'the customer message "58x40 kerak" must be answered by the next tick; roles: '.implode(',', $roles));
    }

    /** Same race in the ordinary respond() path (pre-existing code) — for comparison. */
    public function test_customer_message_during_a_normal_turn_is_answered(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->duringTurn = function () {
            $c = ChatConversation::where('channel', 'telegram')->sole();
            $c->messages = [...$c->messages, ['role' => 'user', 'content' => '58x40 kerak', 'message_id' => 2, 'at' => now()->toIso8601String()]];
            $c->save();
        };
        $this->botUpdate(1, 'Tarozi uchun etiketka kerak');
        $this->at('2026-11-23 10:01');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $roles = array_column($this->bot()->messages, 'role');
        $this->assertGreaterThanOrEqual(2, count($this->calls), 'roles: '.implode(',', $roles));
    }

    public function test_business_reminder_is_sent_as_the_account_and_close_works(): void
    {
        $this->connection();
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 20:00'); // outside working hours: answered at once
        $this->businessUpdate(1, 'Etiketka kerak');
        $this->assertCount(1, $this->calls);

        $this->at('2026-11-24 09:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(2, $this->calls);
        $c = ChatConversation::where('channel', 'business')->sole();
        $this->assertSame('assistant', collect($c->messages)->last()['role']);
        $biz = Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && ($r->data()['business_connection_id'] ?? null) === 'bc1');
        $this->assertCount(2, $biz);

        $this->at('2026-11-24 20:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $lead = ChatLead::sole();
        $this->assertSame('business', $lead->channel);
        $this->assertStringStartsWith('[Unfinished] ', $lead->topic);
    }

    public function test_business_can_reply_off_gets_no_reminder(): void
    {
        $connection = $this->connection();
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 20:00');
        $this->businessUpdate(1, 'Etiketka kerak');
        $connection->update(['can_reply' => false]);

        $this->at('2026-11-24 09:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, $this->calls);
    }

    /** Closing sends nothing to the customer, so it works with can_reply off: the collected request still reaches the manager. */
    public function test_business_can_reply_off_still_closes_as_unfinished(): void
    {
        $connection = $this->connection();
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 20:00');
        $this->businessUpdate(1, 'Etiketka kerak');
        $connection->update(['can_reply' => false]);

        $this->at('2026-11-24 20:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertSame(1, ChatLead::count());
        $this->assertNotNull(ChatConversation::where('channel', 'business')->sole()->sales_state['closed_at'] ?? null);
    }

    public function test_employee_gets_no_reminder(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        TelegramUser::where('telegram_id', '7001')->update(['is_employee' => true]);

        $this->at('2026-11-23 12:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, $this->calls);
    }

    public function test_mark_answered_stops_reminder_and_close(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        $c = $this->bot();
        $c->handled_count = count($c->messages);
        $c->save();

        $this->at('2026-11-23 12:05');
        $this->artisan('sales:assistant-tick');
        $this->at('2026-11-24 10:05');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->calls);
        $this->assertSame(0, ChatLead::count());
    }

    /** A late edit appended after the reminder changes the message count → a second reminder? */
    public function test_late_edit_after_the_reminder_does_not_cause_a_second_reminder(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        $this->at('2026-11-23 12:05');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);

        $this->at('2026-11-23 12:30');
        $c = $this->bot();
        $c->messages = [...$c->messages, ['role' => 'user', 'content' => 'old edited', 'late_edit' => true, 'message_id' => 77, 'at' => now()->toIso8601String()]];
        $c->save();

        $this->at('2026-11-23 15:00');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls, 'only one reminder');
    }

    public function test_typed_phone_goes_into_the_unfinished_lead_unverified(): void
    {
        $this->answers = [$this->answer(['customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '90 111 22 33', 'city' => '', 'company' => ''], 'stage' => 'confirm'])];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak, 901112233');
        $this->at('2026-11-24 10:05');
        $this->artisan('sales:assistant-tick');
        $lead = ChatLead::sole();
        $this->assertSame('+998901112233', $lead->phone);
        $this->assertStringContainsString('not verified', $this->sentTo('-100555')[0]['text']);
    }

    public function test_open_lead_means_no_second_unfinished_lead(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        $lead = ChatLead::create(['channel' => 'telegram', 'name' => 'Ali', 'phone' => '+998901112233', 'locale' => 'uz', 'topic' => 'x']);
        $c = $this->bot();
        $c->sales_state = ['lead_id' => $lead->id] + $c->sales_state;
        $c->save();

        $this->at('2026-11-24 10:05');
        $this->artisan('sales:assistant-tick');
        $this->assertSame(1, ChatLead::count());
        $this->assertNotNull($this->bot()->sales_state['closed_at'] ?? null);
    }

    public function test_long_close_window_is_still_seen_by_the_tick(): void
    {
        SalesAssistantSetting::current()->update(['close_after_hours' => 168]);
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        $this->at('2026-11-23 12:05');
        $this->artisan('sales:assistant-tick');
        $this->at('2026-11-30 10:05');
        $this->artisan('sales:assistant-tick');
        $this->assertSame(1, ChatLead::count());
    }

    /** An empty follow-up reply marks it failed, and the sales chat is still asked to contact the customer. */
    public function test_follow_up_with_empty_reply_is_marked_failed_and_the_manager_is_told(): void
    {
        $this->answers = [$this->answer(['stage' => 'cancelled', 'cancelled' => true, 'follow_up_agreed' => true]), $this->answer(['reply' => '', 'stage' => 'need'])];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Hozircha kerak emas');
        $this->at('2026-12-07 10:01');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);
        $this->assertCount(1, $this->sentTo('-100555'));
        $this->assertStringContainsString('could not write', $this->sentTo('-100555')[0]['text']);
        $this->assertSame('failed', $this->bot()->sales_state['follow_up_sent'] ?? null);
    }

    /** The bot may not write in that chat: no Claude turn, the manager gets the note. */
    public function test_follow_up_goes_to_the_manager_when_business_cannot_reply(): void
    {
        $connection = $this->connection();
        $this->answers = [$this->answer(['stage' => 'cancelled', 'cancelled' => true, 'follow_up_agreed' => true])];
        $this->at('2026-11-23 20:00');
        $this->businessUpdate(1, 'Kerak emas');
        $connection->update(['can_reply' => false]);
        $this->at('2026-12-08 10:01'); // follow_up_at = 2026-12-07 20:00 Tashkent
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->calls);
        $this->assertSame('manager', ChatConversation::where('channel', 'business')->sole()->sales_state['follow_up_sent'] ?? null);
        $this->assertCount(1, $this->sentTo('-100555'));
    }
}
