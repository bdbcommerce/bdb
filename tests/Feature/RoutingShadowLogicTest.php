<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiShadowDraft;
use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Logic review of 13b96e3 (routing by hand_to + shadow mode): collisions with the handled index, the manager
 * take-over check, Waiting for reply, reminders/close, follow-ups, daily limits and manager replies.
 * Failing tests here are open conflicts.
 */
class RoutingShadowLogicTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answers = [];

    /** @var (callable(int): void)|null called inside the fake Claude call with the call number (1-based) */
    public $during = null;

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
            public function __construct(private RoutingShadowLogicTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data');
                if ($this->test->during) {
                    ($this->test->during)(count($this->test->calls));
                }
                $answer = count($this->test->answers) > 1 ? array_shift($this->test->answers) : ($this->test->answers[0] ?? []);

                return ['text' => json_encode($answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        // Monday, working hours.
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function answer(array $override = []): array
    {
        return $override + ['reply' => 'Qanday yordam kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => 'Printer problem.', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'hand_to' => ''];
    }

    private function bot(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function sentTo(string $chat): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r->data()['chat_id'] ?? '') === $chat)
            ->map(fn ($p) => $p[0]->data()['text'])->values()->all();
    }

    private function setStatus(string $name, string $status): AiEmployee
    {
        return tap(AiEmployee::where('name', $name)->sole())->update(['status' => $status]);
    }

    /** 1. A manager writes while the handing turn runs: that reply is dropped, but the colleague's handover turn still answers. */
    public function test_handover_turn_does_not_answer_after_a_manager_took_over_during_the_handing_turn(): void
    {
        $this->setStatus('Javohir', 'live');
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Salom, men Javohir. Printer modeli qanday?']),
        ];
        $this->during = function (int $call) {
            if ($call === 1) {
                $c = ChatConversation::sole();
                $c->messages = [...$c->messages, ['role' => 'admin', 'author' => 'Aziz', 'content' => 'Men yordam beraman.', 'at' => now()->toIso8601String()]];
                $c->save();
            }
        };
        $this->bot(1, 'Chek printer oq qog‘oz chiqaryapti');

        $this->assertSame([], $this->sentTo('7001'), 'a manager took the chat during the turn — no AI message may follow');
    }

    /** 2. Ping-pong: the colleague's handover turn hands straight back; the customer gets two "X will help" lines and no answer. */
    public function test_handover_turn_cannot_bounce_the_chat_back(): void
    {
        $this->setStatus('Javohir', 'live');
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Malika yordam beradi.', 'hand_to' => 'sales']),
            $this->answer(['reply' => 'Unused']),
        ];
        $this->bot(1, 'Printer kerak, ham ishlamayapti');

        $this->assertSame(['Javohir yordam beradi.', 'Malika yordam beradi.'], $this->sentTo('7001'));
        $c = ChatConversation::sole();
        // Either the bounce is refused (kind stays support) or the new owner answers; today neither happens.
        $this->assertTrue(($c->sales_state['kind'] ?? 'sales') === 'support' || count($this->calls) === 3,
            'kind flipped back to '.($c->sales_state['kind'] ?? 'sales').' after '.count($this->calls).' turns, the customer was told "Malika will help" and nobody answers');
    }

    /**
     * 3. Live sales hands to a shadow colleague: the hand-off line is sent, the real answer is only a draft.
     * The chat leaves Waiting for reply and the nudge treats the waiting customer as silent.
     */
    public function test_pending_shadow_answer_after_a_live_handoff_keeps_the_chat_waiting_and_is_not_nudged(): void
    {
        $this->setStatus('Javohir', 'shadow');
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Printer modelini yozing.']),
        ];
        $this->bot(1, 'Printer oq qog‘oz chiqaryapti');
        $this->assertSame('pending', AiShadowDraft::sole()->status);

        $c = ChatConversation::sole();
        $this->assertTrue($c->awaiting_reply, 'the customer\'s question is unanswered (only a pending draft) but the chat left Waiting for reply');

        $settings = SalesAssistantSetting::current();
        $this->assertNull(SalesAssistant::nudgeDue($c, $settings, now()->addHours($settings->remind_after_hours + 1)),
            'a "you have not answered us" reminder is due for a customer who is waiting for our answer');
        $this->assertNotSame('close', SalesAssistant::nudgeDue($c, $settings, now()->addHours($settings->close_after_hours + 1)),
            'an [Unfinished] "customer stopped answering" lead is due for a customer waiting for our answer');
    }

    /** 4. Same root cause through the daily limit: the hand-off line uses the last turn, the colleague never answers. */
    public function test_handoff_line_on_the_last_allowed_turn_leaves_the_customer_unanswered_and_off_waiting(): void
    {
        $this->setStatus('Javohir', 'live');
        Cache::put('sales-assistant-turns:'.now()->format('Y-m-d').':telegram:7001', SalesAssistant::DAILY_PER_CHAT - 1, now()->addDay());
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Unused']),
        ];
        $this->bot(1, 'Printer ishlamayapti');

        $this->assertSame(['Javohir yordam beradi.'], $this->sentTo('7001'));
        $this->assertTrue(ChatConversation::sole()->awaiting_reply, 'the colleague hit the limit; the customer only got "Javohir will help" and is off Waiting for reply');
    }

    /** 5. A manager answers the customer directly: the pending shadow draft stays pending and can still be sent afterwards. */
    public function test_manager_reply_makes_a_pending_draft_stale(): void
    {
        $this->setStatus('Malika', 'shadow');
        $this->answers = [$this->answer(['reply' => 'Qanday etiketka kerak?'])];
        $this->bot(1, 'Etiketka kerak');
        $draft = AiShadowDraft::sole();

        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Aziz']);
        $c = ChatConversation::sole();
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => '58x40 etiketka bormi?'])->assertRedirect();

        $this->assertNotSame('pending', $draft->fresh()->status, 'the manager answered himself — the AI draft is out of date');
    }

    /** 6. Shadow sales + live colleague: the colleague's sent answer does not make the sales hand-off draft stale. */
    public function test_live_colleague_reply_makes_the_shadow_handoff_draft_stale(): void
    {
        $this->setStatus('Malika', 'shadow');
        $this->setStatus('Javohir', 'live');
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Salom, men Javohir. Printer modeli?']),
        ];
        $this->bot(1, 'Printer ishlamayapti');

        $this->assertSame(['Salom, men Javohir. Printer modeli?'], $this->sentTo('7001'));
        $this->assertNotSame('pending', AiShadowDraft::sole()->status, '"Javohir will help" is still waiting to be sent after Javohir already answered');
    }

    /** 7. Follow-up by a shadow employee: the draft is created, but the sales chat is told the assistant "could not write". */
    public function test_shadow_follow_up_is_not_reported_as_failed(): void
    {
        $this->answers = [$this->answer(['reply' => 'Qanday etiketka kerak?'])];
        $this->bot(1, 'Etiketka kerak');
        $c = ChatConversation::sole();
        $c->sales_state = ['cancelled_at' => now()->addMinute()->toIso8601String(), 'follow_up_at' => now()->addDays(14)->toIso8601String(), 'stage' => 'cancelled'] + $c->sales_state;
        $c->save();
        $this->setStatus('Malika', 'shadow');
        Carbon::setTestNow(Carbon::parse('2026-12-08 10:00', 'Asia/Tashkent')); // Tuesday, working hours
        $this->answers = [$this->answer(['reply' => 'Salom! Etiketka hali kerakmi?', 'stage' => 'need'])];

        $this->assertTrue(app(SalesAssistant::class)->followUp($c->fresh()));

        $this->assertSame('pending', AiShadowDraft::sole()->status);
        $note = collect($this->sentTo('-100555'))->first(fn ($t) => str_contains($t, 'Offer follow-up'));
        $this->assertStringNotContainsString('could not write', (string) $note, 'the follow-up waits as a shadow draft, yet the sales chat is told it failed');
        $this->assertNotSame('failed', $c->fresh()->sales_state['follow_up_sent']);
    }

    /** 8. sendDraft ignores a switched-off Premium-account connection, which the staff reply refuses. */
    public function test_send_draft_refuses_a_disabled_premium_connection(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '5000', 'user_chat_id' => '5000', 'is_enabled' => false, 'can_reply' => true]);
        $c = ChatConversation::create(['uuid' => 'u-1', 'channel' => 'business', 'external_id' => '7001', 'business_connection_id' => 'bc1', 'locale' => 'uz',
            'messages' => [['role' => 'user', 'content' => 'Salom', 'message_id' => 1, 'at' => now()->toIso8601String()]], 'message_count' => 1]);
        $draft = AiShadowDraft::create(['chat_conversation_id' => $c->id, 'ai_employee_id' => AiEmployee::where('name', 'Malika')->value('id'), 'draft' => 'Salom!']);

        $sent = app(SalesAssistant::class)->sendDraft($draft, 'Salom!', 'Aziz');

        $this->assertFalse($sent, 'the connection is switched off in the admin, but the draft went out on behalf of the account');
        $this->assertSame([], $this->sentTo('7001'));
    }

    /** 9. sales_state.kind is per session: after 24 h idle the customer handed to support starts with sales again. */
    public function test_routing_survives_a_new_session(): void
    {
        $this->setStatus('Javohir', 'live');
        $this->answers = [
            $this->answer(['reply' => 'Javohir yordam beradi.', 'hand_to' => 'support']),
            $this->answer(['reply' => 'Salom, men Javohir.']),
            $this->answer(['reply' => 'Yana nima?']),
        ];
        $this->bot(1, 'Printer ishlamayapti');
        $this->travel(25)->hours();
        $this->bot(2, 'Printer yana ishlamayapti');

        $this->assertCount(2, ChatConversation::all());
        $this->assertStringStartsWith('You are Javohir', $this->calls[2]['system'], 'the new session went back to the sales consultant');
    }
}
