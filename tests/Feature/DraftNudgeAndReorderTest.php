<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\Order;
use App\Models\OrderMonth;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\OrderBook;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Step 6: one AI reminder for a quiet draft order after 3 days; reorder hints to the sales chat. */
class DraftNudgeAndReorderTest extends TestCase
{
    use RefreshDatabase;

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555', 'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent')); // Tuesday, working hours
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private DraftNudgeAndReorderTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode(['reply' => 'Salom! 3 ta skaner boʻyicha davom etamizmi?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'questions',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draftAged(int $days, array $extraMessages = []): Order
    {
        $user = TelegramUser::create(['telegram_id' => '7001', 'contact_type' => 'client', 'step' => TelegramUser::STEP_DONE]);
        $at = now()->subDays($days);
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '7001', 'locale' => 'uz',
            'messages' => [['role' => 'user', 'content' => '3 ta skaner', 'at' => $at->toIso8601String(), 'message_id' => 1],
                ['role' => 'assistant', 'content' => 'Qaysi dastur?', 'at' => $at->toIso8601String(), 'message_id' => 2], ...$extraMessages],
            'sales_state' => ['handled' => 2 + count($extraMessages)]]);
        $draft = Order::create(['telegram_user_id' => $user->id, 'chat_conversation_id' => $conversation->id, 'status' => 'draft', 'items' => [['product' => 'Zebra DS2208', 'details' => '3 pcs']]]);
        Order::whereKey($draft->id)->update(['updated_at' => $at]);

        return $draft->refresh();
    }

    private function toCustomer(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === '7001')->values()->all();
    }

    public function test_a_quiet_draft_gets_one_reminder_after_3_days(): void
    {
        $this->assertFalse(app(SalesAssistant::class)->draftNudge($this->draftAged(2)));
        Order::query()->delete();
        TelegramUser::query()->delete();
        ChatConversation::query()->delete();

        $draft = $this->draftAged(3);
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, $this->toCustomer());
        $this->assertStringContainsString('started an order 3 days ago', end($this->calls));
        $this->assertNotNull($draft->refresh()->nudged_at);

        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, $this->toCustomer()); // once
    }

    public function test_no_reminder_when_off_out_of_hours_or_someone_wrote_since(): void
    {
        $draft = $this->draftAged(4, [['role' => 'admin', 'content' => 'Taklif yubordim', 'at' => now()->subDay()->toIso8601String(), 'message_id' => 3]]);
        $this->assertFalse(app(SalesAssistant::class)->draftNudge($draft));
        $this->assertSame([], $this->toCustomer());

        Order::whereKey($draft->id)->update(['nudged_at' => null]);
        SalesAssistantSetting::current()->update(['draft_nudge_days' => 0]);
        $this->assertFalse(app(SalesAssistant::class)->draftNudge($draft->refresh()));

        SalesAssistantSetting::current()->update(['draft_nudge_days' => 3]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 21:00', 'Asia/Tashkent'));
        $this->assertFalse(app(SalesAssistant::class)->draftNudge($draft->refresh()));
        $this->assertNull($draft->refresh()->nudged_at); // still due tomorrow morning
    }

    public function test_no_reminder_when_the_request_went_to_a_manager(): void
    {
        $draft = $this->draftAged(5);
        $draft->conversation->update(['sales_state' => ['handled' => 2, 'stage' => 'handed_over']]);
        $this->assertFalse(app(SalesAssistant::class)->draftNudge($draft->refresh()));
        $this->assertSame([], $this->toCustomer());
        $this->assertSame([], $this->calls);
    }

    public function test_a_steady_reorder_that_is_overdue_gives_one_hint_to_the_sales_chat(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7002', 'name' => 'Aziz']);
        foreach (['2026-08-01', '2026-08-31', '2026-09-30'] as $d) {
            Order::create(['telegram_user_id' => $user->id, 'status' => 'confirmed', 'confirmed_at' => Carbon::parse($d), 'items' => [['product' => 'Ribbon 110x300', 'details' => '10']]]);
        }
        Order::create(['telegram_user_id' => $user->id, 'status' => 'confirmed', 'confirmed_at' => Carbon::parse('2026-11-20'), 'items' => [['product' => 'Labels 58x40', 'details' => '1']]]);

        $due = app(OrderBook::class)->dueReorders();
        $this->assertCount(1, $due);
        $this->assertSame(['ribbon 110x300', 30], [$due[0]['product'], $due[0]['every']]);

        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $hints = collect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data()))->filter(fn ($m) => str_contains($m['text'], 'Reorder due?'));
        $this->assertCount(1, $hints);
        $this->assertStringContainsString('about every 30 days', $hints->first()['text']);

        // Month totals count too; switched off → nothing.
        OrderMonth::create(['telegram_user_id' => $user->id, 'month' => '2026-07', 'orders' => 1, 'items' => ['Labels 58x40' => 1]]);
        SalesAssistantSetting::current()->update(['reorder_hints' => false]);
        $this->travel(1)->days();
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        $this->assertCount(1, collect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data()))->filter(fn ($m) => str_contains($m['text'], 'Reorder due?')));
    }
}
