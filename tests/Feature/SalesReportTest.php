<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesReport;
use App\Models\SalesReportSetting;
use App\Models\ScheduledTaskRun;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesReport\ClaudeSalesAnalyst;
use App\Services\SalesReport\SalesReporter;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '-1001111111111';

    /** What the fake Claude got, and what it answers. */
    public array $calls = [];

    public ?array $answer = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.anthropic.key' => 'sk-ant-test', 'services.telegram.report_delay_ms' => 0]);
        InvoiceParserSetting::current()->update(['source_chat_id' => self::GROUP, 'target_chat_id' => '-1002222222222']);
        Http::fake(['api.telegram.org/*' => Http::sequence()->whenEmpty(Http::response(['ok' => true, 'result' => ['message_id' => 77]]))]);

        $test = $this;
        $this->app->instance(ClaudeSalesAnalyst::class, new class($test) extends ClaudeSalesAnalyst
        {
            public function __construct(private SalesReportTest $test) {}

            public function analyze(string $apiKey, string $model, string $system, string $data, array $schema): array
            {
                $this->test->calls[] = compact('model', 'system', 'data', 'schema');

                return ['text' => json_encode($this->test->answer), 'model' => 'claude-opus-5-5', 'input_tokens' => 10000, 'output_tokens' => 2000, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0];
            }
        });
    }

    private function chat(string $channel, string $externalId, array $messages, ?string $connection = null): ChatConversation
    {
        return ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $externalId, 'business_connection_id' => $connection,
            'locale' => 'ru', 'messages' => $messages, 'message_count' => count($messages)]);
    }

    private function at(string $ago): string
    {
        return now()->sub($ago)->toIso8601String();
    }

    private function seedWeek(): void
    {
        TelegramUser::create(['telegram_id' => '501', 'name' => 'Ali <b>Valiyev</b>', 'company' => 'Mega Market', 'phone' => '+998901234567']);
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9', 'user_chat_id' => '9', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $this->chat('business', '501', [
            ['role' => 'user', 'content' => 'Old message from last month', 'at' => $this->at('20 days')],
            ['role' => 'user', 'content' => 'Zebra DS2208 narxi? Tel +998 90 123 45 67', 'at' => $this->at('3 days')],
            ['role' => 'owner', 'content' => '1 250 000 soʻm, 5 dona bor', 'at' => now()->sub('3 days')->addMinutes(6)->toIso8601String()],
            ['role' => 'user', 'content' => 'Ok, 5 ta olamiz, hisob yuboring', 'at' => $this->at('2 days')],
        ], 'bc1');
        $this->chat('telegram', '502', [
            ['role' => 'user', 'content' => 'Salom', 'at' => $this->at('1 day')],
            ['role' => 'assistant', 'content' => 'Assalomu alaykum!'],
            ['role' => 'user', 'content' => 'Dilnoza', 'aside' => true, 'at' => $this->at('1 day')],
            ['role' => 'admin', 'author' => 'Dilnoza', 'content' => 'Qanday yordam bera olamiz?', 'at' => $this->at('20 hours')],
        ]);
        $this->chat('group', '-100777', [['role' => 'member', 'sender' => 'X', 'content' => 'group talk', 'at' => $this->at('1 day')]]);
    }

    private function answer(array $conversations): void
    {
        $this->answer = [
            'conversations' => $conversations,
            'totals' => ['leads' => 1, 'orders' => 1, 'won' => 1, 'open' => 0, 'lost' => 0, 'support' => 0, 'other' => 1, 'conversion_pct' => 100],
            'team' => [['name' => 'Sales: Aziz', 'conversations' => 1, 'won' => 1, 'avg_score' => 8.5, 'strengths' => 'Fast price', 'improve' => 'Ask for details']],
            'went_well' => ['Fast replies'], 'problems' => ['<script>x</script>'], 'knowledge_gaps' => [],
            'conclusion' => 'Good week', 'next_week_goal' => 'Reply in 15 min',
        ];
    }

    /** @return list<array{chat: string, text: string}> */
    private function sent(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => ['chat' => (string) $p[0]['chat_id'], 'text' => $p[0]['text']])->values()->all();
    }

    public function test_report_posts_each_conversation_then_the_summary_to_the_source_group_only(): void
    {
        $this->seedWeek();
        $this->answer([
            ['ref' => 1, 'kind' => 'other', 'result' => 'none', 'handled_by' => 'Staff: Dilnoza', 'summary' => 'Greeting only', 'next_step' => '', 'lost_reason' => '', 'score' => 0, 'score_comment' => ''],
            ['ref' => 2, 'kind' => 'order', 'result' => 'won', 'handled_by' => 'Sales: Aziz', 'summary' => '5 × DS2208 <a href="x">', 'next_step' => 'Send the invoice', 'lost_reason' => '', 'score' => 9, 'score_comment' => 'Fast'],
            ['ref' => 99, 'kind' => 'lead', 'result' => 'open', 'handled_by' => 'x', 'summary' => 'Made up', 'next_step' => '', 'lost_reason' => '', 'score' => 5, 'score_comment' => ''],
        ]);

        $this->artisan('sales:weekly-report')->assertSuccessful();

        // What Claude got: this week only, phones hidden, prices kept, names of who answered, measured reply time, no groups.
        $data = $this->calls[0]['data'];
        $this->assertSame('claude-opus-5-5', $this->calls[0]['model']);
        $this->assertStringNotContainsString('Old message', $data);
        $this->assertStringNotContainsString('123 45 67', $data);
        $this->assertStringContainsString('[phone]', $data);
        $this->assertStringContainsString('1 250 000', $data);
        $this->assertStringContainsString('Sales: Aziz', $data);
        $this->assertStringContainsString('Staff: Dilnoza', $data);
        $this->assertStringContainsString('Sales: Aziz: 1 replies, average 6 min', $data);
        $this->assertStringNotContainsString('group talk', $data);
        $this->assertStringNotContainsString("Customer: Dilnoza\n", $data, 'registration steps (aside) are left out');
        $this->assertStringContainsString('Uzbek', $this->calls[0]['system']);

        $sent = $this->sent();
        $this->assertSame([self::GROUP], array_values(array_unique(array_column($sent, 'chat'))), 'only the Telegram group');
        $texts = array_column($sent, 'text');
        $this->assertCount(4, $texts, 'intro, the order, other chats, summary');
        $this->assertStringContainsString('Haftalik savdo hisoboti', $texts[0]);
        $this->assertStringContainsString('🟢 <b>Ali &lt;b&gt;Valiyev&lt;/b&gt; (Mega Market)</b>', $texts[1]);
        $this->assertStringContainsString('5 × DS2208 &lt;a href=&quot;x&quot;&gt;', $texts[1]);
        $this->assertStringContainsString('⭐ 9/10', $texts[1]);
        $this->assertStringContainsString('Send the invoice', $texts[1]);
        $this->assertStringContainsString('Greeting only', $texts[2]);
        $this->assertStringNotContainsString('Made up', implode('', $texts), 'refs Claude invented are dropped');
        $this->assertStringContainsString('Sales: Aziz', $texts[3]);
        $this->assertStringContainsString('&lt;script&gt;', $texts[3]);
        $this->assertStringContainsString('100%', $texts[3]);

        $report = SalesReport::sole();
        $this->assertSame(['sent', 2, self::GROUP], [$report->status, $report->conversations, $report->chat_id]);
        $this->assertSame(0.08, round($report->cost_usd, 2));
    }

    public function test_an_empty_week_sends_one_note_without_calling_claude(): void
    {
        $this->artisan('sales:weekly-report')->assertSuccessful();

        $this->assertSame([], $this->calls);
        $this->assertCount(1, $this->sent());
        $this->assertSame('empty', SalesReport::sole()->status);
    }

    public function test_without_a_group_nothing_is_read_or_sent(): void
    {
        $this->seedWeek();
        foreach (['', '555123'] as $id) { // a user ID is not a group
            InvoiceParserSetting::current()->update(['source_chat_id' => $id]);
            $this->artisan('sales:weekly-report')->assertFailed();
        }
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->sent());
        $this->assertSame(['failed', 'failed'], SalesReport::pluck('status')->all());
    }

    public function test_switched_off_skips_the_scheduled_run_but_not_a_manual_one(): void
    {
        SalesReportSetting::current()->update(['enabled' => false]);
        $this->artisan('sales:weekly-report')->assertSuccessful();
        $this->assertSame(0, SalesReport::count());

        $this->artisan('sales:weekly-report --manual')->assertSuccessful();
        $this->assertSame('manual', SalesReport::sole()->trigger);
    }

    public function test_preview_prints_the_data_and_calls_nobody(): void
    {
        $this->seedWeek();
        $this->artisan('sales:weekly-report --preview')->expectsOutputToContain('Zebra DS2208')->assertSuccessful();
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->sent());
    }

    public function test_phone_masking_keeps_prices(): void
    {
        $r = app(SalesReporter::class);
        foreach (['+998901234567', '+998 90 123 45 67', '90 123-45-67', '(90) 123 45 67', '+998 901 23 45 67', '+998 90 12 345 67', '90.123.45.67', '901 23 45 67', '90 12 345 67', '+7 (916) 123-45-67'] as $phone) {
            $this->assertSame('tel [phone].', $r->maskPhones("tel {$phone}."), $phone);
        }
        foreach (['1 250 000 soʻm', '250 000 000', '12500000', 'INN 301234567', 'Заказ №345678912', 'DS2208', '2026-11-21', '5 dona', '90 123 45 67 89'] as $text) {
            $this->assertSame($text, $r->maskPhones($text));
        }
    }

    public function test_long_texts_odd_json_late_edits_and_left_out_chats(): void
    {
        $this->seedWeek();
        // A late edit of an old message is not a new customer message.
        $this->chat('telegram', '503', [['role' => 'user', 'content' => 'edited old text', 'late_edit' => true, 'at' => $this->at('1 hour')]]);
        $this->answer([
            ['ref' => 2, 'kind' => 'lead', 'result' => 'open', 'handled_by' => 'Sales: Aziz', 'summary' => str_repeat('A & B ', 2000), 'next_step' => '', 'lost_reason' => '', 'score' => 7, 'score_comment' => ''],
        ]);
        $this->answer['team'][] = ['name' => ['x'], 'conversations' => 1, 'won' => 0, 'avg_score' => 1, 'strengths' => '', 'improve' => ''];
        $this->answer['conclusion'] = str_repeat('Long & long ', 1000);

        $this->artisan('sales:weekly-report')->assertSuccessful();

        $this->assertStringNotContainsString('edited old text', $this->calls[0]['data']);
        $texts = array_column($this->sent(), 'text');
        foreach ($texts as $text) {
            $this->assertLessThanOrEqual(4096, mb_strlen($text));
            $this->assertStringNotContainsString('&amp;amp;', $text);
            $this->assertDoesNotMatchRegularExpression('/&[#a-z0-9]*…$/i', $text);
        }
        $this->assertStringContainsString('A &amp; B', $texts[1], 'shortened, escaped once, tags kept');
        $this->assertStringContainsString('<b>', $texts[1]);
        $this->assertStringContainsString('Customer 502 — ?', $texts[2], 'the chat Claude left out is still listed');
        $this->assertSame('sent', SalesReport::sole()->status);
    }

    public function test_too_many_chats_are_capped_and_all_left_out_is_not_an_empty_week(): void
    {
        foreach (range(1, SalesReporter::MAX_CHATS + 5) as $i) {
            $this->chat('telegram', (string) (1000 + $i), [['role' => 'user', 'content' => "Question {$i}", 'at' => $this->at('1 day')]]);
        }
        $data = app(SalesReporter::class)->collect(now()->subDays(7), now()->addMinute(), ['telegram']);
        $this->assertCount(SalesReporter::MAX_CHATS, $data['chats']);
        $this->assertSame(5, $data['omitted']);
    }

    public function test_show_page_keeps_only_plain_tags_and_http_links(): void
    {
        $report = SalesReport::create(['period_start' => now()->subWeek(), 'period_end' => now(), 'trigger' => 'manual', 'status' => 'sent',
            'html' => "<b onclick=\"x()\">Hi</b>\n<a href=\"javascript:alert(1)\" onclick=\"x()\">bad</a> <a href=\"https://bdb.uz/admin/chat-conversations/5\" onmouseover=\"x()\">ok</a><script>alert(1)</script>"]);
        $html = $report->safeMessages()[0];
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<a href="https://bdb.uz/admin/chat-conversations/5" target="_blank" rel="noopener noreferrer">ok</a>', $html);
        $this->assertStringContainsString('<b>Hi</b>', $html);
    }

    public function test_scheduled_saturday_nine_tashkent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00', 'UTC')); // a Thursday
        $next = ScheduledTasks::nextRun('sales-report');
        $this->assertSame('2026-11-21 09:00 Sat', $next->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d H:i D'));
        Carbon::setTestNow();
    }

    public function test_admin_page_settings_and_send_now(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.sales-report.index'))->assertOk()->assertSee(self::GROUP)->assertSee('Sales report');

        $this->actingAs($admin)->put(route('admin.sales-report.update'), ['language' => 'ru', 'channels' => ['business']])->assertSessionHasNoErrors();
        $s = SalesReportSetting::current();
        $this->assertSame(['ru', ['business'], false], [$s->language, $s->channels, $s->enabled]);
        $this->actingAs($admin)->put(route('admin.sales-report.update'), ['language' => 'de', 'channels' => ['web']])->assertSessionHasErrors(['language', 'channels.0']);
        $this->actingAs($admin)->put(route('admin.sales-report.update'), ['language' => 'uz'])->assertSessionHasErrors('channels');

        $this->withoutDefer();
        $this->actingAs($admin)->post(route('admin.sales-report.run'))->assertSessionHasNoErrors();
        $this->assertSame('success', ScheduledTaskRun::where('task', 'sales-report')->sole()->status);
        $this->assertSame('manual', SalesReport::sole()->trigger);
        $this->actingAs($admin)->get(route('admin.sales-report.show', SalesReport::sole()))->assertOk();

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.sales-report.index'))->assertStatus(302);
        $this->assertSame(1, SalesReport::count());
    }
}
