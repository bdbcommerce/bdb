<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesReport;
use App\Models\SalesReportSetting;
use App\Models\User;
use App\Services\SalesReport\ClaudeSalesAnalyst;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\TestCase;

/** Edge cases of the daily sales report (--daily → InvoiceParserSetting::json_chat_id). */
class SalesReportDailyTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '-1001111111111';

    private const INVOICE_CHAT = '-1002222222222';

    private const RECIPIENT = '555000111';

    public array $calls = [];

    public bool $telegramRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.anthropic.key' => 'sk-ant-test', 'services.telegram.report_delay_ms' => 0]);
        InvoiceParserSetting::current()->update(['source_chat_id' => self::GROUP, 'target_chat_id' => self::INVOICE_CHAT, 'json_chat_id' => self::RECIPIENT]);
        Http::fake(['api.telegram.org/*' => fn () => $this->telegramRefuses
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot can\'t initiate conversation with a user'], 403)
            : Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

        $test = $this;
        $this->app->instance(ClaudeSalesAnalyst::class, new class($test) extends ClaudeSalesAnalyst
        {
            public function __construct(private SalesReportDailyTest $test) {}

            public function analyze(string $apiKey, string $model, string $system, string $data, array $schema): array
            {
                $this->test->calls[] = compact('system', 'data');

                return ['text' => json_encode([
                    'conversations' => [['ref' => 1, 'kind' => 'lead', 'result' => 'open', 'handled_by' => 'Staff: Dilnoza', 'summary' => 'Narx soʻradi', 'next_step' => 'Call', 'lost_reason' => '', 'score' => 7, 'score_comment' => 'ok']],
                    'totals' => ['leads' => 1, 'orders' => 0, 'won' => 0, 'open' => 1, 'lost' => 0, 'support' => 0, 'other' => 0, 'conversion_pct' => 0],
                    'team' => [], 'went_well' => [], 'problems' => [], 'knowledge_gaps' => [],
                    'conclusion' => 'Fine', 'next_week_goal' => 'Reply faster',
                ]), 'model' => 'claude-opus-5-5', 'input_tokens' => 100, 'output_tokens' => 50, 'cache_write_tokens' => 0, 'cache_read_tokens' => 0];
            }
        });
    }

    private function todayChat(): void
    {
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '502', 'locale' => 'ru', 'message_count' => 2, 'messages' => [
            ['role' => 'user', 'content' => 'Сколько стоит принтер этикеток?', 'at' => now()->subHours(5)->toIso8601String()],
            ['role' => 'admin', 'author' => 'Dilnoza', 'content' => 'Отправлю прайс', 'at' => now()->subHours(4)->toIso8601String()],
        ]]);
    }

    /** @return list<string> */
    private function chats(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/send'))->map(fn ($p) => (string) $p[0]['chat_id'])->unique()->values()->all();
    }

    private function texts(): array
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]['text'])->values()->all();
    }

    public function test_daily_ignores_days_option_and_never_posts_to_the_group_or_invoice_chat(): void
    {
        $this->todayChat();
        $this->artisan('sales:weekly-report --daily --days=7')->assertSuccessful();

        $this->assertSame([self::RECIPIENT], $this->chats());
        $report = SalesReport::sole();
        $this->assertSame('day', $report->period);
        $this->assertEqualsWithDelta(24, abs($report->period_end->diffInHours($report->period_start)), 0.01);
    }

    public function test_recipient_equal_to_the_invoice_chat_fails_and_sends_nothing(): void
    {
        InvoiceParserSetting::current()->update(['json_chat_id' => ' '.self::INVOICE_CHAT.' ']);
        $this->todayChat();

        $this->artisan('sales:weekly-report --daily --manual')->assertFailed();
        $this->assertSame([], $this->chats());
        $this->assertSame([], $this->calls, 'Claude is not called (nothing paid)');
        $this->assertSame(['day', 'failed', null], [SalesReport::sole()->period, SalesReport::sole()->status, SalesReport::sole()->chat_id]);
    }

    public function test_recipient_equal_to_the_source_group_is_never_used(): void
    {
        // The settings form refuses this now, but a value saved earlier must not route the daily report to the group.
        InvoiceParserSetting::current()->update(['json_chat_id' => self::GROUP]);

        $this->assertNull(SalesReportSetting::dailyChatId(), 'the daily report must never go to the source group');
    }

    public function test_daily_switched_off_still_runs_with_manual_and_weekly_is_unaffected(): void
    {
        SalesReportSetting::current()->update(['daily_enabled' => false, 'enabled' => true]);
        $this->todayChat();

        $this->artisan('sales:weekly-report --daily')->assertSuccessful()->expectsOutputToContain('daily sales report is switched off');
        $this->assertSame(0, SalesReport::count());

        $this->artisan('sales:weekly-report --daily --manual')->assertSuccessful();
        $this->assertSame([self::RECIPIENT], $this->chats());

        $this->artisan('sales:weekly-report')->assertSuccessful();
        $weekly = SalesReport::latest('id')->first();
        $this->assertSame(['week', self::GROUP], [$weekly->period, $weekly->chat_id]);
        $this->assertStringContainsString('one week', $this->calls[1]['system']);
        $this->assertStringContainsString('next week', $this->calls[1]['system']);
        $this->assertStringNotContainsString('{period}', $this->calls[1]['system']);
        $this->assertStringNotContainsString('{next}', $this->calls[0]['system']);
    }

    public function test_weekly_switched_off_does_not_stop_the_daily(): void
    {
        SalesReportSetting::current()->update(['enabled' => false]);
        $this->todayChat();
        $this->artisan('sales:weekly-report --daily')->assertSuccessful();
        $this->assertSame('sent', SalesReport::sole()->status);
    }

    public function test_daily_defaults_to_enabled(): void
    {
        $this->assertTrue(SalesReportSetting::current()->daily_enabled);
    }

    public function test_empty_day_in_each_language(): void
    {
        foreach (['uz' => ['Kunlik savdo hisoboti', 'Bugun Telegram suhbatlari'], 'ru' => ['Ежедневный отчёт по продажам', 'Сегодня разговоров'], 'en' => ['Daily sales report', 'No Telegram conversations today']] as $lang => [$title, $empty]) {
            SalesReport::query()->delete();
            SalesReportSetting::current()->update(['language' => $lang]);

            $this->artisan('sales:weekly-report --daily')->assertSuccessful();
            $report = SalesReport::sole();
            $this->assertSame(['empty', 'day'], [$report->status, $report->period], $lang);
            $this->assertStringContainsString($title, $report->html, $lang);
            $this->assertStringContainsString($empty, $report->html, $lang);
            $this->assertStringNotContainsString('hafta', mb_strtolower($report->html), $lang);
        }
        $this->assertSame([], $this->calls);
    }

    public function test_daily_labels_in_ru_and_en_render(): void
    {
        $this->todayChat();
        foreach (['ru' => ['Ежедневный отчёт по продажам', 'Цель на завтра', 'следующую неделю'], 'en' => ['Daily sales report', 'Goal for tomorrow', 'next week']] as $lang => [$title, $goal, $weekly]) {
            SalesReportSetting::current()->update(['language' => $lang]);
            $this->artisan('sales:weekly-report --daily')->assertSuccessful();
            $html = SalesReport::latest('id')->first()->html;
            $this->assertStringContainsString($title, $html, $lang);
            $this->assertStringContainsString($goal, $html, $lang);
            $this->assertStringNotContainsString($weekly, $html, $lang);
        }
    }

    public function test_telegram_refusing_the_recipient_gives_the_start_the_bot_hint(): void
    {
        $this->telegramRefuses = true;
        $this->todayChat();

        $this->artisan('sales:weekly-report --daily')->assertFailed();
        $report = SalesReport::sole();
        $this->assertSame('failed', $report->status);
        $this->assertStringContainsString('Start', (string) $report->error);
        $this->assertNotNull($report->model, 'the paid analysis is kept');
    }

    public function test_admin_page_without_recipient_and_with_daily_off(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        InvoiceParserSetting::current()->update(['json_chat_id' => null]);
        $this->actingAs($admin)->get(route('admin.sales-report.index'))->assertOk()
            ->assertSee('Daily report recipient')->assertSee('Fill in the JSON recipient', false)
            ->assertSee('Send daily now')->assertSee('Send weekly now')->assertSee('name="daily_enabled"', false);

        InvoiceParserSetting::current()->update(['json_chat_id' => self::RECIPIENT]);
        SalesReportSetting::current()->update(['daily_enabled' => false]);
        $this->actingAs($admin)->get(route('admin.sales-report.index'))->assertOk()->assertSee(self::RECIPIENT)->assertSee('Next: switched off');

        // Unknown period falls back to weekly.
        $this->actingAs($admin)->post(route('admin.sales-report.run'), ['period' => 'hacker'])->assertSessionHasNoErrors();
        $this->assertSame('week', SalesReport::latest('id')->first()?->period);

        // Validation errors keep old input.
        $this->actingAs($admin)->from(route('admin.sales-report.index'))->put(route('admin.sales-report.update'), ['language' => 'de', 'channels' => ['telegram'], 'daily_enabled' => '1'])
            ->assertSessionHasErrors('language')->assertSessionHasInput('daily_enabled', '1');
    }

    public function test_show_page_labels_the_period(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $day = SalesReport::create(['period' => 'day', 'period_start' => now()->subDay(), 'period_end' => now(), 'trigger' => 'manual', 'status' => 'empty', 'chat_id' => self::RECIPIENT, 'html' => 'x']);
        $week = SalesReport::create(['period_start' => now()->subWeek(), 'period_end' => now(), 'trigger' => 'schedule', 'status' => 'empty', 'chat_id' => self::GROUP, 'html' => 'x']);

        $this->actingAs($admin)->get(route('admin.sales-report.show', $day))->assertOk()->assertSee('Daily sales report');
        $this->actingAs($admin)->get(route('admin.sales-report.show', $week))->assertOk()->assertSee('Weekly sales report');
        $this->actingAs($admin)->get(route('admin.sales-report.index'))->assertOk()->assertSee('daily · manual')->assertSee('weekly · schedule');
        auth()->logout();
        $this->get(route('admin.sales-report.show', $day))->assertRedirect();
    }

    public function test_the_scheduled_daily_command_line_is_accepted_by_the_command(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'sales:weekly-report') && str_contains($e->command, '--daily'));
        $this->assertNotNull($event);
        $this->assertSame('0 18 * * *', $event->expression);
        $this->assertSame('Asia/Tashkent', $event->timezone);

        // What schedule:run executes: everything after "artisan", as the shell would split it.
        $args = str_getcsv(trim(Str::after($event->command, '"artisan"')), ' ', '"', '\\');
        $command = $this->app[Kernel::class]->all()['sales:weekly-report'];
        $command->mergeApplicationDefinition();

        // Throws "The "--daily" option does not accept a value." when the event passes --daily=1.
        $input = new ArgvInput(['artisan', ...$args], $command->getDefinition());
        $this->assertTrue($input->getOption('daily'), 'scheduled command line: '.$event->command);
    }
}
