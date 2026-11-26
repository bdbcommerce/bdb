<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\Client;
use App\Models\InvoiceParserSetting;
use App\Models\Order;
use App\Models\OrderMonth;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Companies\CompanyLookup;
use App\Services\SalesAssistant\OrderBook;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Orders: a draft the AI continues until confirmed or cancelled; the last 5 confirmed kept in full, older ones per month. */
class OrderBookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    public array $answer = [];

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
            public function __construct(private OrderBookTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode($this->test->answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    private function reply(array $override = []): array
    {
        return $override + ['reply' => 'Ok', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '+998901112233', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'inns' => [], 'start_new' => false];
    }

    private function say(int $id, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function user(): TelegramUser
    {
        return TelegramUser::where('telegram_id', '7001')->sole();
    }

    public function test_items_make_a_draft_that_a_confirmation_confirms_with_the_company_details(): void
    {
        $this->answer = $this->reply(['items' => [['product' => 'Zebra DS2208', 'details' => '3 pcs']], 'summary' => '3 scanners', 'stage' => 'questions']);
        $this->say(1, '3 ta skaner kerak');
        $draft = Order::sole();
        $this->assertSame(['draft', ['Zebra DS2208: 3 pcs']], [$draft->status, $draft->itemLines()]);

        $company = Client::create(['name' => 'Korzinka LLC', 'inn' => '301234567', 'address' => 'Tashkent', 'status' => 'active']);
        app(CompanyLookup::class)->link($company, $this->user(), null, 'manager');
        $this->answer = $this->reply(['items' => [['product' => 'Zebra DS2208', 'details' => '3 pcs']], 'summary' => '3 scanners', 'stage' => 'confirm', 'confirmed' => true,
            'quoted' => [['product' => 'Zebra DS2208', 'price' => '1 150 000 sum']]]);
        $this->say(2, 'Ha, tasdiqlayman');

        $order = Order::sole();
        $this->assertSame('confirmed', $order->status);
        $this->assertNotNull($order->chat_lead_id);
        $this->assertSame(['name' => 'Korzinka LLC', 'inn' => '301234567', 'address' => 'Tashkent'], $order->company);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Order #'.$order->id)->assertSee('Korzinka LLC')->assertSee('INN 301234567')->assertSee('1 150 000 sum');
    }

    public function test_the_ai_sees_the_draft_and_the_customer_can_start_new_or_cancel(): void
    {
        $this->answer = $this->reply(['items' => [['product' => 'Ribbon 110x300', 'details' => '10']], 'summary' => '10 ribbons']);
        $this->say(1, 'Ribbon kerak');
        $this->answer = $this->reply();
        $this->say(2, 'Salom yana');
        $this->assertStringContainsString('Open draft order (not confirmed yet', end($this->calls));
        $this->assertStringContainsString('Ribbon 110x300: 10', end($this->calls));

        $this->answer = $this->reply(['start_new' => true, 'items' => [['product' => 'Labels 58x40', 'details' => '20 rolls']], 'summary' => 'labels']);
        $this->say(3, 'Yoʻq, yangi buyurtma: yorliqlar');
        $this->assertSame(['cancelled', 'draft'], Order::orderBy('id')->pluck('status')->all());
        $this->assertSame(['Labels 58x40: 20 rolls'], Order::where('status', 'draft')->sole()->itemLines());

        $this->answer = $this->reply(['cancelled' => true]);
        $this->say(4, 'Kerak emas');
        $this->assertSame(0, Order::where('status', 'draft')->count());
    }

    public function test_only_the_last_5_confirmed_stay_and_older_ones_add_up_per_month(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7001']);
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '7001', 'locale' => 'uz', 'messages' => []]);
        $book = app(OrderBook::class);
        foreach (range(1, 7) as $n) {
            Carbon::setTestNow(Carbon::parse(($n <= 2 ? '2026-09-1' : '2026-10-1').$n.' 10:00'));
            $book->confirm($user, $conversation, ['items' => [['product' => 'Ribbon 110x300', 'details' => (string) $n]], 'summary' => "order {$n}",
                'quoted' => [['product' => 'Ribbon', 'price' => '95 000 sum']]], null);
        }
        Carbon::setTestNow();

        $this->assertSame(['order 7', 'order 6', 'order 5', 'order 4', 'order 3'], Order::where('status', 'confirmed')->orderByDesc('confirmed_at')->pluck('summary')->all());
        $month = OrderMonth::sole();
        $this->assertSame(['2026-09', 2, ['Ribbon 110x300' => 2], 190000], [$month->month, $month->orders, $month->items, $month->quoted_sum]);
        $lines = implode("\n", $book->forPrompt($user));
        $this->assertStringContainsString('Last orders:', $lines);
        $this->assertStringContainsString('Earlier orders by month: [{"month":"2026-09","orders":2', $lines);
    }
}
