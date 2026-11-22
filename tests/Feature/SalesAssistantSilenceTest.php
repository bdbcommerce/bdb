<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** End of a conversation: one reminder to a silent customer, the unfinished request after 24 h, the agreed follow-up. */
class SalesAssistantSilenceTest extends TestCase
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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantSilenceTest $test) {}

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

    /** @return list<array> sendMessage calls to $chat */
    private function sentTo(string $chat): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r->data()['chat_id'] ?? '') === $chat)
            ->map(fn ($p) => $p[0]->data())->values()->all();
    }

    private function conversation(): ChatConversation
    {
        return ChatConversation::where('channel', 'telegram')->sole();
    }

    public function test_one_reminder_then_the_unfinished_request_goes_to_the_manager(): void
    {
        $this->answers = [$this->answer(), $this->answer(['reply' => 'Etiketka oʻlchamini yozib yuborsangiz, soʻrovni menejerga topshiraman.'])];
        $this->at('2026-11-23 10:00'); // Monday, working hours
        $this->botUpdate(1, 'Tarozi uchun etiketka kerak');
        $this->assertCount(1, $this->calls);

        $this->at('2026-11-23 11:30'); // not yet
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, $this->calls);

        $this->at('2026-11-23 12:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(2, $this->calls);
        $this->assertStringContainsString('# TASK NOW', $this->calls[1]);
        $this->assertCount(2, $this->sentTo('7001'));
        $this->assertSame('assistant', collect($this->conversation()->messages)->last()['role']);

        $this->at('2026-11-23 16:00'); // only one reminder
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(2, $this->calls);
        $this->assertSame(0, ChatLead::count());

        $this->at('2026-11-24 10:05'); // 24 h after the customer went silent (not after the reminder)
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(2, $this->calls, 'closing needs no Claude turn');
        $lead = ChatLead::sole();
        $this->assertSame('telegram', $lead->channel);
        $this->assertStringStartsWith('[Unfinished] ', $lead->topic);
        $this->assertSame('', $lead->phone);
        $sales = $this->sentTo('-100555');
        $this->assertCount(1, $sales);
        $this->assertStringContainsString('Unfinished request', $sales[0]['text']);
        $this->assertStringEndsWith('Open the chat</a>', $sales[0]['text']);
        $this->assertCount(2, $this->sentTo('7001'), 'the customer gets nothing on close');

        $this->at('2026-11-25 10:05');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertSame(1, ChatLead::count(), 'closed once');
    }

    public function test_no_reminder_outside_working_hours(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 17:30');
        $this->botUpdate(1, 'Etiketka kerak');

        $this->at('2026-11-23 20:00');
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, $this->calls);

        $this->at('2026-11-24 09:05'); // next working morning
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(2, $this->calls);
    }

    public function test_the_customer_answering_after_the_reminder_starts_again(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        $this->at('2026-11-23 12:05');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);

        $this->at('2026-11-23 13:00');
        $this->botUpdate(2, '58x40');
        $this->assertCount(3, $this->calls, 'answered at once');

        $this->at('2026-11-23 15:05'); // a new silence gets its own reminder
        $this->artisan('sales:assistant-tick');
        $this->assertCount(4, $this->calls);

        $this->at('2026-11-24 12:05'); // 24 h from 13:00 not reached
        $this->artisan('sales:assistant-tick');
        $this->assertSame(0, ChatLead::count());
        $this->at('2026-11-24 13:05');
        $this->artisan('sales:assistant-tick');
        $this->assertSame(1, ChatLead::count());
    }

    public function test_no_reminder_when_a_manager_wrote_last_or_the_request_was_handed_over(): void
    {
        $this->answers = [$this->answer()];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Etiketka kerak');
        $c = $this->conversation();
        $c->messages = [...$c->messages, ['role' => 'admin', 'author' => 'Aziz', 'content' => 'Men yordam beraman', 'at' => now()->toIso8601String()]];
        $c->save();

        $this->at('2026-11-24 12:00');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->calls);
        $this->assertSame(0, ChatLead::count());

        // handed over: nothing more to remind
        $c->refresh();
        $c->messages = array_slice($c->messages, 0, -1);
        $c->sales_state = ['stage' => 'handed_over'] + $c->sales_state;
        $c->save();
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->calls);
        $this->assertSame(0, ChatLead::count());
    }

    public function test_nothing_collected_closes_without_a_lead(): void
    {
        $this->answers = [$this->answer(['reply' => 'Assalomu alaykum! Qanday yordam kerak?', 'stage' => 'greeting', 'items' => [], 'summary' => ''])];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Salom');
        $this->at('2026-11-24 10:05');
        $this->artisan('sales:assistant-tick');

        $this->assertSame(0, ChatLead::count());
        $this->assertNotNull($this->conversation()->sales_state['closed_at'] ?? null);
    }

    public function test_agreed_follow_up_is_sent_once_then_the_manager_is_asked(): void
    {
        $this->answers = [$this->answer(['reply' => 'Rahmat! 2 haftadan keyin taklif yuborsak boʻladimi?', 'stage' => 'cancelled', 'cancelled' => true, 'follow_up_agreed' => true]),
            $this->answer(['reply' => 'Assalomu alaykum! Etiketka hali ham kerakmi? Menejerimiz taklif yuboradi.', 'stage' => 'need'])];
        TelegramUser::create(['telegram_id' => '7001', 'name' => 'Ali Valiev', 'phone' => '+998901112233']);
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Hozircha kerak emas');
        $this->assertNotNull($this->conversation()->sales_state['follow_up_at'] ?? null);

        $this->at('2026-12-07 08:00'); // the day has come, but before working hours
        $this->artisan('sales:assistant-tick');
        $this->assertCount(1, $this->calls);

        $this->at('2026-12-07 10:01');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);
        $this->assertStringContainsString('agreed that we may contact them again', $this->calls[1]);
        $this->assertCount(2, $this->sentTo('7001'));
        $note = $this->sentTo('-100555');
        $this->assertCount(1, $note);
        $this->assertStringContainsString('Offer follow-up', $note[0]['text']);
        $this->assertStringContainsString('+998901112233', $note[0]['text']);

        $this->at('2026-12-07 15:00'); // once; and the follow-up message itself gets no reminder
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);
        $this->at('2026-12-08 11:00');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);
        $this->assertSame(0, ChatLead::count());
    }

    public function test_follow_up_is_dropped_when_the_customer_wrote_again(): void
    {
        $this->answers = [$this->answer(['stage' => 'cancelled', 'cancelled' => true, 'follow_up_agreed' => true]), $this->answer(['stage' => 'handed_over'])];
        $this->at('2026-11-23 10:00');
        $this->botUpdate(1, 'Kerak emas');
        $this->at('2026-11-25 10:00');
        $this->botUpdate(2, 'Baribir kerak boʻldi');
        $this->assertCount(2, $this->calls);

        $this->at('2026-12-07 10:01');
        $this->artisan('sales:assistant-tick');
        $this->assertCount(2, $this->calls);
        $this->assertSame([], $this->sentTo('-100555'));
    }
}
