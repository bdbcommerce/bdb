<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\SalesAssistantAi;
use App\Services\SalesReport\SalesReporter;
use App\Services\Telegram\TelegramRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Logic review of the sales assistant: where it collides with other features sharing the same state
 * (voice forwarding, Business registration flags, "Waiting for reply", the tick, the sales report).
 * Each test describes the expected behaviour; failing ones reproduce an open conflict.
 */
class SalesAssistantLogicConflictsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    /** Called inside the fake Claude turn (simulates something happening while Claude thinks). */
    public ?\Closure $duringTurn = null;

    /** Overrides for the fake answer. */
    public array $answerOverride = [];

    /** Called when Telegram receives a Business sendMessage (simulates a webhook arriving meanwhile). */
    public ?\Closure $duringSend = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutDefer();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);

        $test = $this;
        Http::fake(function (Request $request) use ($test) {
            if (str_contains($request->url(), '/sendMessage') && isset($request->data()['business_connection_id']) && $test->duringSend) {
                ($test->duringSend)();
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 900 + count($test->calls)]]);
        });

        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantLogicConflictsTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;
                if ($this->test->duringTurn) {
                    $fn = $this->test->duringTurn;
                    $this->test->duringTurn = null;
                    $fn();
                }

                return ['text' => json_encode($this->test->answer()), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    public function answer(): array
    {
        return $this->answerOverride + ['reply' => 'Salom! Qanday yordam kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false];
    }

    private function botUpdate(int $id, array $message): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, array $message, int $from = 7002): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => $message + ['message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => $from === 7002 ? 'Vali' : 'Owner']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function connection(): TelegramBusinessConnection
    {
        return TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
    }

    /** Conflict 1: the sales branch calls forwardVoice() before $this->messageId is set, so voice never reaches the sales chat. */
    public function test_voice_in_the_bot_chat_is_still_forwarded_to_the_sales_chat(): void
    {
        $this->botUpdate(1, ['voice' => ['file_id' => 'v1', 'duration' => 5]]);

        $forwards = Http::recorded(fn (Request $r) => str_contains($r->url(), '/forwardMessage') || str_contains($r->url(), '/copyMessage'));
        $this->assertCount(1, $forwards, 'the voice should be forwarded to TELEGRAM_CHAT_ID like before');
    }

    /**
     * Conflict 2: an old Business registration (ask_registration + BUSINESS_REGISTERING + step name) makes every
     * customer message `aside`; the sales branch returns before askRegistration, so it never finishes — the chat
     * is hidden from "Waiting for reply" for 30 days while the assistant (or nobody) answers.
     */
    public function test_stale_business_registration_does_not_hide_the_chat_from_waiting_for_reply(): void
    {
        $this->connection();
        // Left from before the rollout: an admin asked for registration info and the Business flow asked the name.
        TelegramUser::create(['telegram_id' => '7002', 'ask_registration' => true, 'step' => TelegramUser::STEP_NAME]);
        Cache::put(TelegramRecorder::BUSINESS_REGISTERING.'7002', true, now()->addDays(30));
        InvoiceParserSetting::current()->update(['api_key' => null]);
        config(['services.anthropic.key' => null]); // the turn fails: nobody answers

        $this->businessUpdate(1, ['text' => 'Narxi qancha?']);

        $conversation = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertEmpty($conversation->messages[0]['aside'] ?? null, 'a real question, not a registration answer');
        $this->assertTrue($conversation->awaiting_reply, 'unanswered customer must be on Waiting for reply');
    }

    /** Conflict 3: Business replies of the assistant are stored as role "bot", which replyState ignores; bot-chat replies (role assistant) clear it. */
    public function test_waiting_for_reply_is_consistent_between_bot_and_business_after_an_assistant_answer(): void
    {
        $this->connection();
        Carbon::setTestNow(Carbon::parse('2026-11-22 21:00', 'Asia/Tashkent')); // outside working hours: answered at once
        $this->botUpdate(1, ['text' => 'Salom']);
        $this->businessUpdate(2, ['text' => 'Salom']);
        Carbon::setTestNow();

        // Two customers (7001 in the bot chat, 7002 in the Premium-account chat), each answered in their own chat.
        $bot = ChatConversation::where('external_id', '7001')->sole();
        $business = ChatConversation::where('external_id', '7002')->sole();
        $this->assertCount(2, $this->calls);
        $this->assertFalse($bot->awaiting_reply);
        $this->assertSame($bot->awaiting_reply, $business->awaiting_reply, 'answered by the assistant in both channels');
        $this->assertSame(['bot', 'bot'], array_map(fn ($m) => $bot->viaOf($m), $bot->messages));
        $this->assertSame(['assistant', 'assistant'], array_map(fn ($m) => $business->viaOf($m), $business->messages));
    }

    /** Conflict 4: the tick ignores "Mark answered" (handled_count) and answers messages that are hours old (e.g. the first run after deploy). */
    public function test_tick_does_not_answer_a_chat_staff_marked_answered(): void
    {
        TelegramUser::create(['telegram_id' => '7001']);
        $conversation = ChatConversation::create(['uuid' => 'u1', 'locale' => 'ru', 'channel' => 'telegram', 'external_id' => '7001', 'messages' => [
            ['role' => 'user', 'content' => 'Narxi qancha?', 'message_id' => 1, 'at' => now()->subHours(20)->toIso8601String()],
        ]]);
        $conversation->markHandled(); // answered by phone

        $this->artisan('sales:assistant-tick')->assertSuccessful();

        $this->assertSame([], $this->calls, 'staff marked it answered 20 h ago — no AI reply now');
    }

    /**
     * Conflict 5: a manager answers while Claude is thinking; the assistant still sends its reply (no re-check after
     * the turn), in working hours, on top of the manager's answer.
     */
    public function test_manager_answering_during_a_turn_is_not_talked_over(): void
    {
        $this->connection();
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00', 'Asia/Tashkent')); // Monday, working hours
        $this->businessUpdate(1, ['text' => 'Salom, skaner kerak']);
        Carbon::setTestNow(now()->addMinutes(6));

        $this->duringTurn = function () {
            $c = ChatConversation::where('channel', 'telegram')->sole();
            $c->messages = [...$c->messages, ['role' => 'owner', 'content' => 'Men Aziz, yordam beraman', 'message_id' => 2, 'at' => now()->toIso8601String()]];
            $c->save();
        };
        $this->artisan('sales:assistant-tick');
        Carbon::setTestNow();

        $this->assertCount(1, $this->calls);
        $this->assertSame([], Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && isset($r->data()['business_connection_id']))->all(),
            'the manager took over during the turn: the assistant\'s reply should be dropped');
    }

    /** Conflict 6: sendAsAccount() saves the messages read before the API call, so a message stored meanwhile is lost. */
    public function test_customer_message_arriving_while_the_business_reply_is_sent_is_not_lost(): void
    {
        $this->connection();
        Carbon::setTestNow(Carbon::parse('2026-11-22 21:00', 'Asia/Tashkent'));
        $this->duringSend = function () {
            $this->duringSend = null;
            $c = ChatConversation::where('channel', 'telegram')->sole();
            $c->messages = [...$c->messages, ['role' => 'user', 'content' => 'Yana bir savol', 'message_id' => 5, 'at' => now()->toIso8601String()]];
            $c->save();
        };
        $this->businessUpdate(1, ['text' => 'Salom']);
        Carbon::setTestNow();

        $contents = array_column(ChatConversation::where('channel', 'telegram')->sole()->messages, 'content');
        $this->assertContains('Yana bir savol', $contents);
    }

    /** Conflict 7: the sales report treats the assistant's answers as "bot", so every chat it answered is reported as still unanswered. */
    public function test_sales_report_does_not_flag_chats_the_assistant_answered_as_unanswered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 21:00', 'Asia/Tashkent'));
        $this->botUpdate(1, ['text' => 'Salom, etiketka kerak']);
        $this->assertCount(1, $this->calls);

        $data = app(SalesReporter::class)->collect(now()->subDay(), now()->addHour(), ['telegram']);
        Carbon::setTestNow();
        $this->assertCount(1, $data['chats']);
        $chat = array_values($data['chats'])[0];
        $this->assertNull($chat['unanswered_since'], 'the assistant answered the customer');
    }

    /** Conflict 8: questions answered by the sales assistant are no longer saved as client requests (ClientRequest::record is never called). */
    public function test_questions_in_the_bot_chat_still_become_client_requests(): void
    {
        // A question the assistant could not answer becomes an open client request (intake answers do not).
        $this->answerOverride = ['reply' => '', 'unknown_question' => 'Is the Zebra ZD421 in stock?'];
        $this->botUpdate(1, ['text' => 'Zebra ZD421 printeringiz bormi va narxi qancha?']);
        $this->assertCount(1, $this->calls);
        $this->assertSame('open', ClientRequest::sole()->status);
    }
}
