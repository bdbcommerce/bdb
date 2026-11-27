<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Services\SalesAssistant\MorningSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** QA for walk-through step 7: timezones, long holidays, summary length / escaping, the tick. */
class OffHoursHolidaysQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $attributes = []): SalesAssistantSetting
    {
        $s = SalesAssistantSetting::current();
        $s->update($attributes + ['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00']);

        return $s->refresh();
    }

    private function at(string $tashkent): Carbon
    {
        return Carbon::parse($tashkent, SalesAssistantSetting::TZ);
    }

    /** Creates a chat the way production does: "now" is the moment of the message, timestamps stored in UTC. */
    private function chatAt(string $tashkent, string $id, string $text): ChatConversation
    {
        Carbon::setTestNow($this->at($tashkent)->utc());
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => $id, 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => $text, 'at' => now()->toIso8601String()]]]);
        Carbon::setTestNow();

        return $c;
    }

    public function test_app_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_summary_includes_a_chat_last_updated_in_the_evening_tashkent_time(): void
    {
        $s = $this->settings();
        // Friday 20:00 Tashkent = 15:00 UTC; nobody touched the chat afterwards.
        $this->chatAt('2027-03-19 20:00', '501', 'Evening question');

        $this->assertSame(1, app(MorningSummary::class)->run($s, $this->at('2027-03-22 09:01')),
            'A chat whose last message was Fri 20:00 Tashkent must be in Monday\'s summary');
    }

    public function test_summary_counts_a_lead_made_in_the_evening_tashkent_time(): void
    {
        $s = $this->settings();
        $this->chatAt('2027-03-19 23:30', '502', 'Night question');
        Carbon::setTestNow($this->at('2027-03-19 19:00')->utc());
        ChatLead::create(['name' => 'A', 'phone' => '+998901234567', 'locale' => 'ru', 'channel' => 'telegram']);
        Carbon::setTestNow();

        app(MorningSummary::class)->run($s, $this->at('2027-03-22 09:01'));
        Http::assertSent(fn (Request $r) => str_contains((string) $r['text'], '1 new request'));
    }

    public function test_summary_does_not_count_a_lead_made_after_opening(): void
    {
        $s = $this->settings();
        $this->chatAt('2027-03-21 23:30', '503', 'Sunday night');
        // Monday 09:30 Tashkent — after opening, must not be in the 09:00–09:59 summary window... the summary runs at 10:00 here.
        Carbon::setTestNow($this->at('2027-03-22 09:30')->utc());
        ChatLead::create(['name' => 'B', 'phone' => '+998901234568', 'locale' => 'ru', 'channel' => 'telegram']);
        Carbon::setTestNow();

        app(MorningSummary::class)->run($s, $this->at('2027-03-22 10:00'));
        Http::assertSent(fn (Request $r) => ! str_contains((string) $r['text'], 'new request'));
    }

    public function test_summary_text_is_escaped_and_fits_telegram_limit(): void
    {
        $s = $this->settings();
        Carbon::setTestNow($this->at('2027-03-20 12:00')->utc()); // Saturday noon: clear of the timezone defect
        for ($i = 1; $i <= 25; $i++) {
            TelegramUser::create(['telegram_id' => (string) (600 + $i), 'name' => str_repeat('<b>Ш&"', 30), 'contact_type' => 'client']);
            ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => (string) (600 + $i), 'locale' => 'ru',
                'messages' => [['role' => 'user', 'content' => str_repeat('<script>Ўзбек & текст ', 40), 'at' => now()->toIso8601String()]],
                'sales_state' => $i % 2 ? ['summary' => str_repeat('<i>summary & </i>', 30)] : null]);
        }
        Carbon::setTestNow();

        $this->assertSame(25, app(MorningSummary::class)->run($s, $this->at('2027-03-22 09:01')));
        Http::assertSent(function (Request $r) {
            $t = (string) $r['text'];
            $visible = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertLessThanOrEqual(4096, mb_strlen($visible), 'visible length');
            $this->assertStringNotContainsString('<script>', $t);
            $this->assertStringNotContainsString('<b>Ш', $t);
            $this->assertStringContainsString('… and 10 more', $t);
            // Only the tags we produce.
            preg_match_all('/<\/?([a-z]+)/', $t, $m);
            $this->assertEmpty(array_diff(array_unique($m[1]), ['b', 'a', 'i']));

            return true;
        });
    }

    public function test_holiday_today_no_summary_then_next_working_day_covers_the_holiday(): void
    {
        $s = $this->settings(['holidays' => '2027-03-22 – 2027-03-23 Navruz']);
        $summary = app(MorningSummary::class);
        $this->assertFalse($summary->due($s, $this->at('2027-03-22 09:01')));
        $this->assertFalse($summary->due($s, $this->at('2027-03-23 12:00')));
        $this->chatAt('2027-03-22 12:00', '701', 'Holiday question');
        $this->assertSame(1, $summary->run($s, $this->at('2027-03-24 09:01')));
        Http::assertSent(fn (Request $r) => str_contains((string) $r['text'], 'since 19.03 18:00'));
    }

    public function test_tick_twice_in_one_day_sends_once_and_runs_with_sales_modes_off(): void
    {
        $s = $this->settings(['bot_mode' => 'off', 'business_mode' => 'off']);
        $this->chatAt('2027-03-21 22:00', '801', 'Sunday');
        Carbon::setTestNow($this->at('2027-03-22 09:01'));
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        Carbon::setTestNow($this->at('2027-03-22 09:02'));
        $this->artisan('sales:assistant-tick')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('2027-03-22', $s->refresh()->morning_summary_sent_on->toDateString());
    }

    public function test_sent_on_compares_the_tashkent_date_late_evening(): void
    {
        // Working hours till 23:30 Tashkent: at 23:10 (18:10 UTC same date) and next day 00:10 UTC = 05:10 Tashkent (closed).
        $s = $this->settings(['work_end' => '23:30']);
        $summary = app(MorningSummary::class);
        $s->update(['morning_summary_sent_on' => '2027-03-22']);
        $this->assertFalse($summary->due($s->refresh(), $this->at('2027-03-22 23:10')));
        $this->assertTrue($summary->due($s, $this->at('2027-03-23 09:05')));
    }

    public function test_next_opening_when_weeks_of_holidays(): void
    {
        // Seven back-to-back ranges: 2027-03-01 … 2027-04-30 closed.
        $s = $this->settings(['holidays' => "2027-03-01 – 2027-03-31 A\n2027-04-01 – 2027-04-30 B"]);
        $now = $this->at('2027-03-01 10:00');
        $open = $s->nextOpening($now);
        $this->assertTrue($s->isWorkingTime($open->copy()->addMinute()) || $open->gt($this->at('2027-04-30 23:59')),
            'nextOpening returned '.$open->format('Y-m-d H:i').' which is not an opening');
    }

    public function test_previous_closing_after_a_long_holiday(): void
    {
        $s = $this->settings(['holidays' => "2027-03-01 – 2027-03-31 A\n2027-04-01 – 2027-04-12 B"]);
        // Opening Tue 13.04; last closing Fri 26.02 18:00 (46 days back).
        $this->assertSame('2027-02-26 18:00', $s->previousClosing($this->at('2027-04-13 09:01'))->format('Y-m-d H:i'));
    }

    public function test_messages_with_bad_or_missing_at_are_skipped(): void
    {
        $s = $this->settings();
        Carbon::setTestNow($this->at('2027-03-20 12:00'));
        ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '901', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'no at'], ['role' => 'user', 'content' => 'bad', 'at' => 'not a date'], ['role' => 'user', 'content' => 'num', 'at' => 12345]]]);
        Carbon::setTestNow();
        $this->assertSame(0, app(MorningSummary::class)->run($s, $this->at('2027-03-22 09:01')));
    }

    public function test_holiday_parse_edge_cases(): void
    {
        $this->assertSame('2027-03-08', SalesAssistantSetting::parseHoliday('8.3.2027 8 March')['from']);
        $this->assertSame('8 March', SalesAssistantSetting::parseHoliday('8.3.2027 8 March')['name']);
        $this->assertNotNull(SalesAssistantSetting::parseHoliday('2027-03-20..2027-03-23 Navruz'));
        $this->assertSame('Навруз байрами', SalesAssistantSetting::parseHoliday('2027-03-21 — Навруз байрами')['name']);
        $this->assertSame(60, mb_strlen(SalesAssistantSetting::parseHoliday('2027-03-21 '.str_repeat('Ў', 200))['name']));
        $this->assertNull(SalesAssistantSetting::parseHoliday(''));
        // Range across a year end.
        $s = $this->settings(['holidays' => '2027-12-31 – 2028-01-02 New Year']);
        $this->assertFalse($s->isWorkingTime($this->at('2027-12-31 10:00')));
        $this->assertSame('2028-01-03 09:00', $s->nextOpening($this->at('2027-12-30 19:00'))->format('Y-m-d H:i'));
        // A holiday on a weekend does not shift anything.
        $s = $this->settings(['holidays' => '2027-03-20 – 2027-03-21 Weekend holiday']);
        $this->assertSame('2027-03-22 09:00', $s->nextOpening($this->at('2027-03-19 19:00'))->format('Y-m-d H:i'));
    }

    public function test_note_on_holiday_in_every_locale_and_long_closure_date(): void
    {
        $s = $this->settings(['holidays' => '2027-03-08 – 2027-03-19 Long break']);
        foreach (['uz', 'ru', 'en'] as $l) {
            $note = $s->offHoursNote($l, $this->at('2027-03-08 10:00'));
            $this->assertStringNotContainsString('chat.', $note, $l);
            $this->assertStringContainsString('Long break', $note, $l);
            $this->assertStringContainsString('22.03', $note, $l); // > 7 days away: the date
        }
    }

    public function test_prompt_context_now_line_on_holiday(): void
    {
        Carbon::setTestNow($this->at('2027-03-22 11:00'));
        $s = $this->settings(['holidays' => '2027-03-22 Navruz']);
        $this->assertNull($s->holidayOn($this->at('2027-03-23 00:30')));
        $this->assertSame('Navruz', $s->holidayOn($this->at('2027-03-21 19:30')->utc()->addHours(5))); // 22.03 00:30 Tashkent given in UTC
        $this->assertSame(['22.03 Navruz'], $s->upcomingHolidays());
    }
}
