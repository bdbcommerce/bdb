<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ExpertQuestion;
use App\Models\InvoiceParserSetting;
use App\Models\Order;
use App\Models\SalesAssistantSetting;
use App\Models\SalesReport;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesReport\ClaudeSalesAnalyst;
use App\Services\SalesReport\SalesMetrics;
use App\Services\SalesReport\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA for walk-through step 8 (SalesMetrics): timezones, periods, double counting, escaping, limits, old reports. */
class SalesMetricsQaTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '-1001111111111';

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        SalesAssistantSetting::current()->update(['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00', 'holidays' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $tashkent): Carbon
    {
        return Carbon::parse($tashkent, SalesAssistantSetting::TZ);
    }

    private function msg(string $role, string $at, array $extra = []): array
    {
        return ['role' => $role, 'content' => 'x', 'at' => $this->at($at)->toIso8601String()] + $extra;
    }

    private function chat(string $channel, string $id, array $messages): ChatConversation
    {
        return ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => $channel, 'external_id' => $id, 'locale' => 'ru', 'messages' => $messages]);
    }

    private function fakeRun(?array $answer): void
    {
        config(['services.telegram.bot_token' => '123:abc', 'services.anthropic.key' => $answer ? 'sk-ant-test' : null, 'services.telegram.report_delay_ms' => 0]);
        InvoiceParserSetting::current()->update(['source_chat_id' => self::GROUP, 'target_chat_id' => '-1002222222222']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
        $test = $this;
        $this->app->instance(ClaudeSalesAnalyst::class, new class($test, $answer) extends ClaudeSalesAnalyst
        {
            public function __construct(private SalesMetricsQaTest $test, private ?array $answer) {}

            public function analyze(string $apiKey, string $model, string $system, string $data, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode($this->answer), 'model' => 'claude-opus-5-5', 'input_tokens' => 10, 'output_tokens' => 10, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0];
            }
        });
    }

    public function test_tashkent_and_utc_periods_give_the_same_numbers_and_bounds_are_half_open(): void
    {
        Carbon::setTestNow($this->at('2027-03-15 10:00'));
        // Mon 2027-03-15. 08:59 Tashkent is off hours, 09:00 working; the boundary messages sit exactly on from/to.
        $this->chat('telegram', '1', [
            $this->msg('user', '2027-03-15 08:59'), $this->msg('assistant', '2027-03-15 09:00'),
            $this->msg('user', '2027-03-15 09:00'), $this->msg('assistant', '2027-03-15 09:01'),
            $this->msg('user', '2027-03-16 08:00'), // = $to, excluded
        ]);
        $from = $this->at('2027-03-15 08:00');
        $to = $this->at('2027-03-16 08:00');
        $tash = app(SalesMetrics::class)->measure($from, $to);
        $utc = app(SalesMetrics::class)->measure($from->copy()->utc(), $to->copy()->utc());

        $this->assertSame($tash, $utc);
        $this->assertSame(2, $tash['ai']['replies']);
        // The 09:00 answer came in working hours: the AI did not cover the night.
        $this->assertSame(['messages' => 1, 'customers' => 1, 'answered_by_ai' => 0, 'holiday_messages' => 0, 'notes' => 0], $tash['off_hours']);
    }

    public function test_rows_created_in_the_first_five_hours_of_a_tashkent_period_are_counted(): void
    {
        $from = $this->at('2027-03-15 00:00'); // = 2027-03-14 19:00 UTC
        $to = $this->at('2027-03-16 00:00');
        $order = Order::create(['telegram_user_id' => TelegramUser::firstOrCreate(['telegram_id' => '555'], ['name' => 'T'])->id, 'status' => 'draft', 'items' => []]);
        $order->forceFill(['created_at' => $this->at('2027-03-15 01:00')->utc()])->saveQuietly();
        $late = Order::create(['telegram_user_id' => TelegramUser::firstOrCreate(['telegram_id' => '555'], ['name' => 'T'])->id, 'status' => 'draft', 'items' => []]);
        $late->forceFill(['created_at' => $this->at('2027-03-16 02:00')->utc()])->saveQuietly(); // next Tashkent day

        $this->assertSame(1, app(SalesMetrics::class)->measure($from, $to)['orders']['drafts']);
    }

    public function test_one_customer_with_bot_and_premium_sessions_counts_once(): void
    {
        Carbon::setTestNow($this->at('2027-03-15 12:00'));
        $this->chat('telegram', '7', [$this->msg('user', '2027-03-15 20:00'), $this->msg('assistant', '2027-03-15 20:01')]);
        $this->chat('business', '7', [$this->msg('user', '2027-03-15 21:00'), $this->msg('assistant', '2027-03-15 21:01', ['via' => 'assistant'])]);
        $m = app(SalesMetrics::class)->measure($this->at('2027-03-15 00:00'), $this->at('2027-03-16 00:00'));

        $this->assertSame(1, $m['ai']['chats']);
        $this->assertSame(2, $m['ai']['replies']);
        $this->assertSame(2, $m['off_hours']['messages']);
        $this->assertSame(1, $m['off_hours']['customers']);
    }

    public function test_grouped_child_expert_questions_are_not_counted(): void
    {
        Carbon::setTestNow($this->at('2027-03-15 12:00'));
        $c = $this->chat('telegram', '8', []);
        $parent = ExpertQuestion::create(['chat_conversation_id' => $c->id, 'question' => 'Q', 'status' => 'asked']);
        ExpertQuestion::create(['chat_conversation_id' => $c->id, 'question' => 'Q', 'status' => 'grouped', 'parent_id' => $parent->id]);

        $this->assertSame(1, app(SalesMetrics::class)->measure($this->at('2027-03-15 00:00'), $this->at('2027-03-16 00:00'))['ai']['experts']['asked']);
    }

    public function test_an_old_answered_session_does_not_count_as_waiting_when_the_newest_session_is_answered(): void
    {
        Carbon::setTestNow($this->at('2027-03-15 18:00'));
        // Older bot session still flagged; the customer's newest session (Premium) was answered by a person.
        $old = $this->chat('telegram', '9', [$this->msg('user', '2027-03-15 10:00')]);
        $new = $this->chat('business', '9', [$this->msg('user', '2027-03-15 11:00'), $this->msg('owner', '2027-03-15 11:05')]);
        ChatConversation::whereKey($old->id)->update(['awaiting_reply' => true, 'last_message_at' => $this->at('2027-03-15 10:00')->utc(), 'updated_at' => $this->at('2027-03-15 10:00')->utc()]);
        ChatConversation::whereKey($new->id)->update(['awaiting_reply' => false, 'last_message_at' => $this->at('2027-03-15 11:05')->utc(), 'updated_at' => $this->at('2027-03-15 11:05')->utc()]);

        $this->assertSame(0, ChatConversation::awaitingReply()->count(), 'the Waiting for reply list says nobody waits');
        $this->assertSame(0, app(SalesMetrics::class)->measure($this->at('2027-03-15 00:00'), $this->at('2027-03-16 00:00'))['managers']['waiting_over_hour']);
    }

    public function test_the_telegram_message_escapes_names_and_stays_under_4096(): void
    {
        $long = str_repeat('<b>Я&', 30);
        $m = ['ai' => ['chats' => 999999, 'replies' => 999999, 'handovers' => 999999, 'leads' => 999999, 'shadow' => ['sent' => 9, 'edited' => 9, 'discarded' => 9],
            'experts' => ['asked' => 99, 'answered' => 99, 'avg_answer_minutes' => 99999]],
            'orders' => ['confirmed' => 9, 'confirmed_sum' => 999999999999, 'drafts' => 9, 'cancelled' => 9,
                'top_products' => array_combine(array_map(fn ($i) => mb_substr($i.$long, 0, 60), range(1, 5)), [1, 1, 1, 1, 1])],
            'companies' => ['linked_by_ai' => 9, 'linked_by_manager' => 9, 'new_clients' => 9],
            'managers' => ['people' => array_map(fn ($i) => ['name' => mb_substr($i.$long, 0, 40), 'replies' => 9, 'avg_seconds' => 999999, 'median_seconds' => 9], range(1, 15)),
                'leads_taken' => 9, 'leads_not_taken' => 9, 'taken_by' => [], 'waiting_over_hour' => 9],
            'off_hours' => ['messages' => 9, 'customers' => 9, 'answered_by_ai' => 9, 'holiday_messages' => 9, 'notes' => 9]];
        foreach (['uz', 'ru', 'en'] as $lang) {
            $text = app(SalesMetrics::class)->telegram($m, $lang);
            $this->assertLessThan(4096, mb_strlen($text));
            $this->assertStringNotContainsString('<b>Я', $text);
            $this->assertStringContainsString('&lt;b&gt;Я&amp;', $text);
        }
    }

    public function test_empty_metrics_render_without_errors(): void
    {
        $text = app(SalesMetrics::class)->telegram(['ai' => [], 'orders' => [], 'companies' => [], 'managers' => [], 'off_hours' => []], 'uz');
        $this->assertStringContainsString('Raqamlar', $text);
    }

    public function test_partial_or_odd_metrics_on_an_old_report_do_not_break_the_admin_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $old = SalesReport::create(['period' => 'week', 'period_start' => now()->subDays(14), 'period_end' => now()->subDays(7), 'trigger' => 'schedule', 'status' => 'sent',
            'metrics' => ['ai' => ['chats' => 0]]]);
        $r = SalesReport::create(['period' => 'week', 'period_start' => now()->subDays(7), 'period_end' => now(), 'trigger' => 'schedule', 'status' => 'failed',
            'metrics' => ['ai' => ['chats' => 3, 'experts' => ['asked' => 1]], 'managers' => ['people' => [['name' => '<script>x</script>']], 'taken_by' => ['<img src=x onerror=1>' => 2]]]]);
        $legacy = SalesReport::create(['period' => 'week', 'period_start' => now()->subDays(21), 'period_end' => now()->subDays(14), 'trigger' => 'schedule', 'status' => 'sent', 'html' => 'hi']);

        $this->actingAs($admin)->get(route('admin.sales-report.show', $r))->assertOk()
            ->assertSee('▲ +3', false)->assertDontSee('<script>x', false)->assertDontSee('<img src=x', false)->assertSee('&lt;img src=x', false);
        $this->actingAs($admin)->get(route('admin.sales-report.show', $legacy))->assertOk()->assertDontSee('data-metrics', false);
        $this->assertTrue($r->previous()->is($old));
    }

    public function test_show_page_is_admin_only(): void
    {
        $r = SalesReport::create(['period' => 'day', 'period_start' => now()->subDay(), 'period_end' => now(), 'trigger' => 'schedule', 'status' => 'empty', 'metrics' => ['ai' => ['chats' => 1]]]);
        $this->get(route('admin.sales-report.show', $r))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.sales-report.show', $r))->assertRedirect(route('admin.login'));
    }

    public function test_phones_in_product_names_are_masked_for_claude(): void
    {
        $this->fakeRun(['conversations' => [], 'totals' => [], 'team' => [], 'went_well' => [], 'problems' => [], 'knowledge_gaps' => [], 'conclusion' => 'ok', 'next_week_goal' => '']);
        $this->chat('telegram', '10', [['role' => 'user', 'content' => 'Salom', 'at' => now()->subHour()->toIso8601String()], ['role' => 'assistant', 'content' => 'Ha', 'at' => now()->subMinutes(59)->toIso8601String()]]);
        $o = Order::create(['telegram_user_id' => TelegramUser::firstOrCreate(['telegram_id' => '555'], ['name' => 'T'])->id, 'status' => 'confirmed', 'items' => [['product' => 'Printer, tel +998 90 123 45 67']], 'quoted' => [['price' => '100']]]);
        $o->forceFill(['confirmed_at' => now()->subMinutes(30)])->saveQuietly();

        $report = app(SalesReporter::class)->run(now()->subDay(), now());

        $this->assertSame('sent', $report->status, (string) $report->error);
        $this->assertStringContainsString('## Measured numbers', $this->calls[0]);
        $this->assertStringNotContainsString('123 45 67', $this->calls[0]);
        $this->assertLessThan(strpos($this->calls[0], "\n## Conversations\n"), strpos($this->calls[0], '## Measured numbers'));
        $this->assertSame(1, $report->metrics['orders']['confirmed']);
        // The Telegram numbers message: the rest of the report masks phones (maskPhones), so this one should too.
        $this->assertStringNotContainsString('123 45 67', (string) $report->html);
        $this->assertStringContainsString('📈', (string) $report->html);
    }

    public function test_empty_and_failed_reports_keep_their_numbers(): void
    {
        $this->fakeRun(null); // no Anthropic key
        $empty = app(SalesReporter::class)->run(now()->subDay(), now(), 'manual', 'week');
        $this->assertSame('empty', $empty->status);
        $this->assertIsArray($empty->metrics);

        $this->chat('telegram', '11', [['role' => 'user', 'content' => 'Salom', 'at' => now()->subHour()->toIso8601String()]]);
        $failed = app(SalesReporter::class)->run(now()->subDay(), now(), 'manual', 'week');
        $this->assertSame('failed', $failed->status);
        $this->assertSame(0, $failed->fresh()->metrics['ai']['replies']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', (string) $failed->error);
        $off = SalesAssistantSetting::current()->isWorkingTime(now()->subHour()) ? 0 : 1;
        $this->assertSame($off, $failed->fresh()->metrics['off_hours']['messages']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.sales-report.index'))->assertOk()->assertSee(route('admin.sales-report.show', $failed));
    }

    public function test_the_same_person_as_staff_and_sales_gets_one_row(): void
    {
        $m = app(SalesMetrics::class)->measure(now()->subDay(), now(), ['Staff: Aziz' => [60], 'Sales: Aziz' => [120]]);
        $this->assertCount(1, $m['managers']['people'], 'one person, one row');
    }

    public function test_performance_of_a_busy_week(): void
    {
        Carbon::setTestNow($this->at('2027-03-19 12:00'));
        $rows = [];
        for ($i = 0; $i < 1500; $i++) {
            $messages = [];
            for ($j = 0; $j < 120; $j++) {
                $messages[] = ['role' => $j % 2 ? 'assistant' : 'user', 'content' => str_repeat('matn ', 40), 'at' => $this->at('2027-03-15 08:00')->addMinutes($i + $j * 30)->toIso8601String()];
            }
            $rows[] = ['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => (string) (100000 + $i), 'locale' => 'ru', 'messages' => json_encode($messages), 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            ChatConversation::insert($chunk);
        }
        unset($rows, $chunk);
        $mem = memory_get_usage(true);
        $t = microtime(true);
        $m = app(SalesMetrics::class)->measure($this->at('2027-03-13 09:00'), $this->at('2027-03-20 09:00'));
        fwrite(STDERR, sprintf("\n[QA metrics 1500x120] %.2f s, +%d MB, peak %d MB, replies %d\n", microtime(true) - $t, (memory_get_usage(true) - $mem) / 1048576, memory_get_peak_usage(true) / 1048576, $m['ai']['replies']));
        $this->assertSame(1500 * 60, $m['ai']['replies']);
    }
}
