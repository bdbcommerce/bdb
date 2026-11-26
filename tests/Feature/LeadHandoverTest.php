<?php

namespace Tests\Feature;

use App\Models\CatalogSetting;
use App\Models\ChatLead;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\LeadDesk;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Step 5: the handover note (temperature, competitor, why, prices quoted, next step), "I take it" and the reminder. */
class LeadHandoverTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent')); // Tuesday, working hours
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private LeadHandoverTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                return ['text' => json_encode($this->test->answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        $this->answer = ['reply' => 'Rahmat! Menejer tez orada javob beradi.', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'confirm',
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '+998901112233', 'city' => 'Toshkent', 'company' => ''], 'business_type' => 'shop', 'software' => '',
            'items' => [['product' => 'Zebra DS2208', 'details' => '3 pcs']], 'summary' => '3 scanners for 3 checkouts', 'confirmed' => true, 'unknown_question' => '',
            'cancelled' => false, 'follow_up_agreed' => false, 'inns' => [],
            'temperature' => 'hot', 'competitor' => 'Scan Market offers 1 050 000', 'handover_reason' => 'The customer confirmed the order.',
            'next_step' => 'Send the invoice for 3 scanners', 'quoted' => [['product' => 'Zebra DS2208', 'price' => '1 150 000 sum']]];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function say(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function tap(array $query): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => random_int(1000, 99999), 'callback_query' => $query + ['id' => 'cb'.random_int(1, 9999)]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function sent(string $method): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/'.$method))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    public function test_the_request_carries_the_handover_note_and_the_take_button(): void
    {
        CatalogSetting::current()->forceFill(['last_success_at' => Carbon::parse('2026-11-24 04:15', 'UTC')])->save(); // 09:15 Tashkent
        $this->say(1, 'Ha, tasdiqlayman');

        $lead = ChatLead::sole();
        $this->assertSame(['hot', 'Scan Market offers 1 050 000', 'The customer confirmed the order.', 'Send the invoice for 3 scanners'],
            [$lead->temperature, $lead->competitor, $lead->handover_reason, $lead->next_step]);
        $this->assertSame([['product' => 'Zebra DS2208', 'price' => '1 150 000 sum']], $lead->quoted['items']);

        $message = collect($this->sent('sendMessage'))->first(fn ($m) => (string) $m['chat_id'] === '-100555' && str_contains($m['text'], 'New request'));
        $this->assertStringContainsString('<b>Temperature:</b> 🔥 Hot — wants to buy now', $message['text']);
        $this->assertStringContainsString('<b>Competitor:</b> Scan Market offers 1 050 000', $message['text']);
        $this->assertStringContainsString('<b>Why a manager:</b> The customer confirmed the order.', $message['text']);
        $this->assertStringContainsString('<b>Prices quoted</b> (catalog of 24.11 09:15): Zebra DS2208 — 1 150 000 sum', $message['text']);
        $this->assertStringContainsString('<b>Next step:</b> Send the invoice for 3 scanners', $message['text']);
        $this->assertSame('lead:'.$lead->id.':take', json_decode(json_encode($message['reply_markup']), true)['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_the_first_manager_to_tap_takes_it_and_others_are_told(): void
    {
        $this->say(1, 'Ha, tasdiqlayman');
        $lead = ChatLead::sole();
        $message = ['message_id' => 77, 'chat' => ['id' => -100555, 'type' => 'supergroup']];

        $this->tap(['data' => 'lead:'.$lead->id.':take', 'from' => ['id' => 501, 'first_name' => 'Nodira'], 'message' => $message]);
        $this->tap(['data' => 'lead:'.$lead->id.':take', 'from' => ['id' => 502, 'first_name' => 'Jasur'], 'message' => $message]);

        $lead->refresh();
        $this->assertSame(['Nodira', '501'], [$lead->taken_by, $lead->taken_by_telegram_id]);
        $answers = collect($this->sent('answerCallbackQuery'))->pluck('text')->all();
        $this->assertSame(['It is yours.', 'Already taken by Nodira.'], $answers);
        $edit = $this->sent('editMessageReplyMarkup')[0];
        $this->assertSame(77, $edit['message_id']);
        $this->assertStringContainsString('Taken by Nodira', json_encode($edit['reply_markup'], JSON_UNESCAPED_UNICODE));
    }

    public function test_a_tap_outside_the_sales_chat_takes_nothing(): void
    {
        $this->say(1, 'Ha, tasdiqlayman');
        $lead = ChatLead::sole();
        $this->tap(['data' => 'lead:'.$lead->id.':take', 'from' => ['id' => 7001, 'first_name' => 'Ali'], 'message' => ['message_id' => 5, 'chat' => ['id' => 7001, 'type' => 'private']]]);
        $this->assertNull($lead->refresh()->taken_at);
    }

    public function test_a_request_nobody_took_reminds_the_main_admin_once_after_working_minutes(): void
    {
        $admin = TelegramUser::create(['telegram_id' => '900', 'contact_type' => 'employee', 'is_employee' => true, 'is_main_admin' => true, 'bot_chat_at' => now()]);
        $this->say(1, 'Ha, tasdiqlayman');
        $toAdmin = fn () => collect($this->sent('sendMessage'))->filter(fn ($m) => (string) $m['chat_id'] === '900')->values();

        Carbon::setTestNow(now()->addMinutes(10));
        app(LeadDesk::class)->tick();
        $this->assertCount(0, $toAdmin());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertSame(1, app(LeadDesk::class)->tick());
        $this->assertStringContainsString('Request not taken for 15 min', $toAdmin()[0]['text']);
        $this->assertSame(0, app(LeadDesk::class)->tick()); // once

        // A taken request is never reminded.
        ChatLead::query()->update(['reminded_at' => null, 'taken_at' => now()]);
        $this->assertSame(0, app(LeadDesk::class)->tick());
    }
}
