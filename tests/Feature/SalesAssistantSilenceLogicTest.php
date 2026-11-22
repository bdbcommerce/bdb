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

/**
 * Logic review of the silence handling (reminder / unfinished close / follow-up): collisions with leads (is_handled,
 * lead_hash), managers writing (admin / owner), session split and "Waiting for reply".
 */
class SalesAssistantSilenceLogicTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answers = [];

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
            public function __construct(private SalesAssistantSilenceLogicTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;
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

    private function businessUpdate(int $id, string $text, int $from = 7002): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => $from === 7002 ? 'Vali' : 'Aziz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
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

    /**
     * Unfinished close → the customer comes back in the same session and confirms with a phone. apply() finds the
     * unfinished lead as the "open" one and only rewrites its topic: the lead keeps phone '' and the sales chat never
     * hears that the request is now confirmed.
     */
    public function test_confirming_after_an_unfinished_close_reaches_the_manager_with_the_phone(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Tarozi uchun etiketka kerak');
        $this->at('2026-11-24 10:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $lead = ChatLead::sole();
        $this->assertSame('', $lead->phone);
        $this->assertCount(1, $this->sentTo('-100555'), 'the unfinished post');

        $this->answers = [$this->answer(['reply' => 'Rahmat! Soʻrov menejerga yuborildi.', 'stage' => 'handed_over', 'confirmed' => true,
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '901112233', 'city' => '', 'company' => ''],
            'items' => [['product' => 'Labels', 'details' => '58x40, for scales']], 'summary' => 'Labels 58x40 for scales.'])];
        $this->at('2026-11-24 12:00');
        $this->botUpdate(2, '58x40, tel 901112233, ha toʻgʻri');

        $this->assertSame('+998901112233', ChatLead::latest('id')->first()->phone, 'the confirmed request must carry the phone');
        $this->assertCount(2, $this->sentTo('-100555'), 'the manager must be told the unfinished request is now confirmed');
    }

    /**
     * A confirmed request the manager already handled (is_handled) → the customer asks one more thing → goes silent.
     * The close posts the same, already handled request again as "[Unfinished]" (lead_hash is not checked on close).
     */
    public function test_close_does_not_duplicate_a_request_the_manager_already_handled(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'name' => 'Ali', 'phone' => '+998901112233', 'phone_verified_at' => now()]);
        $summary = 'Labels 58x40 for scales.';
        $this->answers = [$this->answer(['reply' => 'Rahmat! Menejerga yubordim.', 'stage' => 'handed_over', 'confirmed' => true, 'summary' => $summary]),
            $this->answer(['reply' => 'Ha, Toshkent boʻylab yetkazamiz. Yana savol bormi?', 'stage' => 'questions', 'summary' => $summary])];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Ha, 58x40 etiketka, toʻgʻri');
        ChatLead::sole()->update(['is_handled' => true]);

        $this->at('2026-11-23 10:10');
        $this->botUpdate(2, 'Yetkazib berish bormi');

        $this->at('2026-11-24 10:15');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertNotNull($this->bot()->sales_state['closed_at'] ?? null);
        $this->assertSame(1, ChatLead::count(), 'no "[Unfinished]" copy of a request that was confirmed and handled');
    }

    /**
     * Customer cancelled and agreed to a follow-up; a manager then wrote the last word ("we will write in 2 weeks").
     * On the day, followUp() pays for a Claude turn, apply() drops the reply because a manager wrote last, the state
     * becomes follow_up_sent = 'failed' and nobody is told — the agreed follow-up is lost silently.
     */
    public function test_follow_up_is_not_lost_when_a_manager_wrote_last(): void
    {
        TelegramUser::create(['telegram_id' => '7001', 'name' => 'Ali', 'phone' => '+998901112233']);
        $this->answers = [$this->answer(['reply' => 'Rahmat! 2 haftadan keyin taklif yuborsak boʻladimi?', 'stage' => 'cancelled', 'cancelled' => true, 'follow_up_agreed' => true]),
            $this->answer(['reply' => 'Assalomu alaykum! Etiketka hali ham kerakmi?', 'stage' => 'need'])];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Hozircha kerak emas');
        $c = $this->bot();
        $c->messages = [...$c->messages, ['role' => 'admin', 'author' => 'Aziz', 'content' => 'Yaxshi, 2 haftadan keyin yozamiz', 'at' => now()->addMinutes(20)->toIso8601String()]];
        $c->save();

        $this->at('2026-12-07 10:01');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $state = $this->bot()->sales_state;
        $this->assertNotSame('failed', $state['follow_up_sent'] ?? null, 'follow-up turn spent and dropped; calls: '.count($this->calls));
        $this->assertCount(1, $this->sentTo('-100555'), 'the sales chat must still be reminded of the agreed offer');
    }

    /**
     * Business: the customer cancelled (follow-up agreed); days later the owner wrote to them, which started a NEW
     * session (24 h idle). followUp() only looks for customer messages, so it writes into the OLD session; that
     * bumps its updated_at, so the customer's answer is recorded there too (recorder picks latest updated_at), while
     * Waiting for reply (scopeAwaitingReply) and the tick (groupBy → last by id) only look at the newest-id session.
     * If the assistant stays silent, the customer's answer is invisible everywhere.
     */
    public function test_answer_to_a_follow_up_is_not_hidden_behind_a_newer_owner_session(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $this->answers = [$this->answer(['reply' => 'Rahmat! 2 haftadan keyin taklif yuborsak boʻladimi?', 'stage' => 'cancelled', 'cancelled' => true, 'follow_up_agreed' => true]),
            $this->answer(['reply' => 'Assalomu alaykum! Etiketka hali ham kerakmi?', 'stage' => 'need']),
            $this->answer(['reply' => '', 'stage' => 'need', 'unknown_question' => ''])];
        $this->at('2026-11-23 20:00'); // outside working hours: answered at once (follow-up due 2026-12-07 20:00)
        $this->businessUpdate(1, 'Hozircha kerak emas');
        $this->assertSame(1, ChatConversation::where('channel', 'business')->count());

        $this->at('2026-11-26 11:00'); // the owner writes himself: a new session
        $this->businessUpdate(2, 'Assalomu alaykum, katalogni yubordim', 9000);
        $this->assertSame(2, ChatConversation::where('channel', 'business')->count());

        $this->at('2026-12-08 10:01');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $followUps = count($this->calls);

        $this->at('2026-12-08 10:30');
        $this->businessUpdate(3, 'Ha, hali ham kerak');

        $this->assertSame(1, ChatConversation::query()->awaitingReply()->where('channel', 'business')->count(),
            'the customer\'s answer must show in Waiting for reply (follow-up calls: '.$followUps.', sessions: '
            .ChatConversation::where('channel', 'business')->get()->map(fn ($c) => $c->id.':'.implode(',', array_column($c->messages, 'role')).':awaiting='.(int) $c->awaiting_reply)->implode(' | ').')');
    }
}
