<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiSandboxChat;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\InvoiceParserSetting;
use App\Models\Order;
use App\Models\OrderMonth;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\LeadDesk;
use App\Services\SalesAssistant\OrderBook;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA: orders (draft / confirm / roll), "I take it" and the lead reminder. */
class OrderLeadDeskQaTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'Asia/Tashkent'));
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private OrderLeadDeskQaTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                return ['text' => json_encode($this->test->answer), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reply(array $override = []): array
    {
        return $override + ['reply' => 'Ok', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
            'customer' => ['first_name' => 'Ali', 'last_name' => '', 'phone' => '+998901112233', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
            'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false, 'inns' => [], 'start_new' => false];
    }

    private function confirmReply(string $summary = '3 scanners', string $product = 'Zebra DS2208'): array
    {
        return $this->reply(['items' => [['product' => $product, 'details' => '3 pcs']], 'summary' => $summary, 'stage' => 'confirm', 'confirmed' => true]);
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

    public function test_confirming_twice_makes_one_order(): void
    {
        $this->answer = $this->confirmReply();
        $this->say(1, 'Ha');
        $this->say(2, 'Ha, tasdiqlayman');
        $this->answer = $this->confirmReply('3 scanners, delivery Monday');
        $this->say(3, 'Dushanba kuni yetkazing');
        $this->assertSame(1, ChatLead::count());
        $this->assertSame(['confirmed'], Order::pluck('status')->all());
    }

    public function test_no_phantom_draft_after_a_confirmation(): void
    {
        $this->answer = $this->confirmReply();
        $this->say(1, 'Ha');
        // The customer just says thanks: Claude returns no items, state still holds the confirmed ones.
        $this->answer = $this->reply(['stage' => 'handed_over']);
        $this->say(2, 'Rahmat');
        $this->assertSame(0, Order::where('status', 'draft')->count(), 'A new draft duplicating the confirmed order was created');
    }

    public function test_no_draft_recreated_after_cancel(): void
    {
        $this->answer = $this->reply(['items' => [['product' => 'Ribbon', 'details' => '10']], 'summary' => 'ribbons']);
        $this->say(1, 'Ribbon kerak');
        $this->answer = $this->reply(['cancelled' => true, 'stage' => 'cancelled']);
        $this->say(2, 'Kerak emas');
        $this->answer = $this->reply(['stage' => 'cancelled']);
        $this->say(3, 'Xayr');
        $this->assertSame(0, Order::where('status', 'draft')->count(), 'The cancelled draft came back from the conversation state');
    }

    public function test_unfinished_lead_later_confirmed_gives_one_order_on_that_lead(): void
    {
        $this->answer = $this->reply(['items' => [['product' => 'Zebra DS2208', 'details' => '3 pcs']], 'summary' => '3 scanners', 'stage' => 'questions']);
        $this->say(1, 'Skaner kerak');
        $conversation = ChatConversation::sole();
        $lead = ChatLead::create(['channel' => 'telegram', 'name' => 'Ali', 'phone' => '+998901112233', 'topic' => '[Unfinished] 3 scanners', 'locale' => 'uz']);
        $conversation->update(['sales_state' => ['lead_id' => $lead->id, 'lead_hash' => md5('3 scanners'), 'lead_partial' => true] + (array) $conversation->sales_state]);

        $this->answer = $this->confirmReply();
        $this->say(2, 'Ha');
        $this->assertSame(1, ChatLead::count());
        $order = Order::sole();
        $this->assertSame(['confirmed', $lead->id], [$order->status, $order->chat_lead_id]);
    }

    public function test_a_new_confirmation_over_the_daily_limit_does_not_overwrite_an_old_order(): void
    {
        $this->answer = $this->confirmReply();
        $this->say(1, 'Ha');
        $first = Order::sole();
        ChatLead::query()->update(['is_handled' => true]);
        Cache::put('sales-assistant-leads:'.now()->format('Y-m-d').':7001', SalesAssistant::LEADS_PER_DAY, now()->addDay());

        $this->answer = $this->confirmReply('10 ribbons', 'Ribbon 110x300');
        $this->say(2, 'Yana 10 ta ribbon, tasdiqlayman');
        $this->assertSame(['Zebra DS2208: 3 pcs'], $first->refresh()->itemLines(), 'The earlier confirmed order was overwritten with the new items');
    }

    public function test_roll_keeps_months_apart_and_adds_into_an_existing_month(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7002']);
        $conversation = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '7002', 'locale' => 'uz', 'messages' => []]);
        $book = app(OrderBook::class);
        $dates = ['2026-08-31 23:00', '2026-09-01 01:00', '2026-09-15 10:00', '2026-10-01 10:00', '2026-10-02 10:00', '2026-10-03 10:00', '2026-10-04 10:00', '2026-10-05 10:00'];
        foreach ($dates as $n => $d) {
            Carbon::setTestNow(Carbon::parse($d, 'UTC'));
            $book->confirm($user, $conversation, ['items' => [['product' => $n % 2 ? 'A' : 'B', 'details' => '1']], 'summary' => "o{$n}", 'quoted' => [['product' => 'x', 'price' => '1 000 sum']]], null);
        }
        $this->assertSame(5, Order::where('status', 'confirmed')->count());
        $months = OrderMonth::orderBy('month')->get()->map(fn ($m) => [$m->month, $m->orders, $m->quoted_sum])->all();
        // Months are Tashkent months: 2026-08-31 23:00 UTC is 1 September in Tashkent.
        $this->assertSame([['2026-09', 3, 3000]], $months);
    }

    public function test_sandbox_creates_no_orders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->answer = $this->confirmReply() + ['temperature' => 'hot', 'quoted' => [['product' => 'Zebra', 'price' => '1']]];
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.start'), ['ai_employee_id' => $malika->id, 'prompt' => 'published', 'via' => 'bot']);
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.message', AiSandboxChat::sole()), ['message' => 'Ha, 901112233']);
        $this->answer = $this->reply(['items' => [['product' => 'Ribbon', 'details' => '1']], 'summary' => 'r']);
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.message', AiSandboxChat::sole()), ['message' => 'Ribbon']);
        $this->assertSame([0, 0], [Order::count(), ChatLead::count()]);
    }

    public function test_html_is_escaped_in_the_request_and_the_order_page(): void
    {
        $this->answer = $this->confirmReply('<script>alert(1)</script>', '<b>Zebra</b>') + ['competitor' => '<i>Rival</i> & co', 'next_step' => '<a href="x">call</a>',
            'handover_reason' => '', 'temperature' => 'warm', 'quoted' => [['product' => '<u>Z</u>', 'price' => '1 < 2']]];
        $this->say(1, 'Ha');
        $text = collect($this->sent('sendMessage'))->first(fn ($m) => str_contains($m['text'], 'New request'))['text'];
        $this->assertStringContainsString('&lt;i&gt;Rival&lt;/i&gt; &amp; co', $text);
        $this->assertStringContainsString('&lt;a href=&quot;x&quot;&gt;call&lt;/a&gt;', $text);
        $this->assertStringContainsString('&lt;u&gt;Z&lt;/u&gt; — 1 &lt; 2', $text);
        $this->assertStringNotContainsString('<script>', $text);

        $admin = User::factory()->create(['is_admin' => true]);
        $order = Order::sole();
        $html = $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;b&gt;Zebra&lt;/b&gt;', $html);
        $this->actingAs($admin)->get(route('admin.telegram-users.show', TelegramUser::where('telegram_id', '7001')->sole()))->assertOk()
            ->assertSee('#'.$order->id)->assertDontSee('<b>Zebra</b>', false);
    }

    public function test_the_request_message_stays_under_4096_visible_chars_with_long_notes(): void
    {
        $long = fn (int $n) => str_repeat('Ж', $n);
        $this->answer = $this->reply(['items' => collect(range(1, 20))->map(fn ($i) => ['product' => $long(120), 'details' => $long(120)])->all(),
            'summary' => $long(800), 'stage' => 'confirm', 'confirmed' => true,
            'customer' => ['first_name' => $long(40), 'last_name' => $long(40), 'phone' => '+998901112233', 'city' => $long(60), 'company' => $long(120)],
            'temperature' => 'hot', 'competitor' => $long(200), 'handover_reason' => $long(300), 'next_step' => $long(300),
            'quoted' => collect(range(1, 20))->map(fn ($i) => ['product' => $long(100), 'price' => $long(60)])->all()]);
        $this->say(1, 'Ha');
        $text = collect($this->sent('sendMessage'))->first(fn ($m) => str_contains($m['text'], 'New request'))['text'];
        $visible = mb_strlen(html_entity_decode(strip_tags($text)));
        $this->assertLessThanOrEqual(4096, $visible);
        $this->assertLessThanOrEqual(4096, mb_strlen($text), 'raw HTML length (Telegram limit is on the parsed text; informative)');
    }

    public function test_a_tap_in_another_group_takes_nothing_and_expert_callbacks_are_not_swallowed(): void
    {
        $lead = ChatLead::create(['channel' => 'telegram', 'name' => 'Ali', 'phone' => '+998901112233', 'topic' => 'x', 'locale' => 'uz', 'transcript' => ['conversation_id' => 1]]);
        $this->tap(['data' => 'lead:'.$lead->id.':take', 'from' => ['id' => 501, 'first_name' => 'N'], 'message' => ['message_id' => 1, 'chat' => ['id' => -100999, 'type' => 'supergroup']]]);
        $this->assertNull($lead->refresh()->taken_at);
        $this->assertCount(0, $this->sent('editMessageReplyMarkup'));

        $desk = app(LeadDesk::class);
        $this->assertFalse($desk->callback(['id' => '1', 'data' => 'xq:5:accept', 'from' => ['id' => 1], 'message' => ['chat' => ['id' => -100555]]]));
        $this->assertFalse($desk->callback(['id' => '1', 'data' => 'lead:5:take:extra', 'from' => ['id' => 1], 'message' => ['chat' => ['id' => -100555]]]));
    }

    public function test_the_reminder_respects_working_time(): void
    {
        TelegramUser::create(['telegram_id' => '900', 'contact_type' => 'employee', 'is_employee' => true, 'is_main_admin' => true, 'bot_chat_at' => now()]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 17:55', 'Asia/Tashkent')); // Tuesday, 5 minutes before closing (default 09–18)
        $lead = ChatLead::create(['channel' => 'telegram', 'name' => 'Ali', 'phone' => '+998901112233', 'topic' => 'x', 'locale' => 'uz', 'transcript' => ['conversation_id' => 1]]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 23:00', 'Asia/Tashkent'));
        $this->assertSame(0, app(LeadDesk::class)->tick());
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:09', 'Asia/Tashkent'));
        $this->assertSame(0, app(LeadDesk::class)->tick());
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:11', 'Asia/Tashkent'));
        $this->assertSame(1, app(LeadDesk::class)->tick());
        $this->assertSame(0, app(LeadDesk::class)->tick());
        $this->assertNotNull($lead->refresh()->reminded_at);
    }

    public function test_order_page_access(): void
    {
        $user = TelegramUser::create(['telegram_id' => '7003']);
        $order = Order::create(['telegram_user_id' => $user->id, 'status' => 'draft', 'items' => null]);
        $this->get(route('admin.orders.show', $order))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.orders.show', $order))->assertRedirect();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->assertSee('No items.');
        OrderMonth::create(['telegram_user_id' => $user->id, 'month' => '2026-09', 'orders' => 2, 'items' => ['A' => 2], 'quoted_sum' => 1500000]);
        $this->actingAs($admin)->get(route('admin.telegram-users.show', $user))->assertOk()->assertSee('2026-09')->assertSee('1 500 000 sum');
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('lead_remind_minutes', false);
    }
}
