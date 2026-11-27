<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\MorningSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Walk-through step 7: answering fully off hours, holidays, the morning summary. */
class OffHoursHolidaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.chat_id' => '-100555']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
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

    public function test_holiday_lines_are_parsed_and_close_the_office(): void
    {
        $this->assertSame(['from' => '2027-03-21', 'to' => '2027-03-21', 'name' => 'Navruz'], SalesAssistantSetting::parseHoliday('2027-03-21 Navruz'));
        $this->assertSame(['from' => '2027-03-20', 'to' => '2027-03-23', 'name' => 'Navruz'], SalesAssistantSetting::parseHoliday(' 20.03.2027 – 23.03.2027  Navruz '));
        $this->assertSame('', SalesAssistantSetting::parseHoliday('2027-09-01')['name']);
        $this->assertNull(SalesAssistantSetting::parseHoliday('Navruz 2027-03-21'));
        $this->assertNull(SalesAssistantSetting::parseHoliday('2027-02-30 Wrong'));
        $this->assertNull(SalesAssistantSetting::parseHoliday('2027-03-23 – 2027-03-20 Backwards'));
        $this->assertNull(SalesAssistantSetting::parseHoliday('2027-01-01 – 2027-03-01 Too long'));

        // Mon 22.03.2027 is a holiday: closed all day, it opens on Wednesday.
        $s = $this->settings(['holidays' => "2027-03-22 – 2027-03-23 Navruz\n2027-09-01"]);
        $this->assertFalse($s->isWorkingTime($this->at('2027-03-22 11:00')));
        $this->assertTrue($s->isWorkingTime($this->at('2027-03-24 11:00')));
        $this->assertSame('2027-03-24 09:00', $s->nextOpening($this->at('2027-03-19 19:00'))->format('Y-m-d H:i'));
        $this->assertSame('2027-03-19 18:00', $s->previousClosing($this->at('2027-03-24 09:00'))->format('Y-m-d H:i'));
        $this->assertSame(0, $s->workingMinutesBetween($this->at('2027-03-22 09:00'), $this->at('2027-03-23 18:00')));

        $note = $s->offHoursNote('en', $this->at('2027-03-22 11:00'));
        $this->assertStringStartsWith('Today our office is closed for Navruz.', $note);
        $this->assertStringContainsString('09:00', $note);
        $this->assertStringStartsWith('Bugun bayram kuni', $s->offHoursNote('uz', $this->at('2027-09-01 11:00')));
        $this->assertStringNotContainsString('closed for', $s->offHoursNote('en', $this->at('2027-03-19 19:00')));
    }

    public function test_the_ai_answers_fully_by_default_and_knows_when_managers_are_back(): void
    {
        config(['services.telegram.sales_off_hours' => 'ai']);
        Carbon::setTestNow($this->at('2027-03-15 10:00'));
        $s = $this->settings(['off_hours_mode' => null, 'holidays' => '2027-03-21 Navruz']);
        $this->assertSame('ai', $s->offHoursMode());
        $prompt = $s->systemPrompt();
        $this->assertStringContainsString('a manager will confirm it when the office opens', $prompt);
        $this->assertStringContainsString('closed on holidays: 21.03 Navruz', $prompt);
        Carbon::setTestNow();
    }

    public function test_settings_save_holidays_and_refuse_unreadable_lines(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $form = ['bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 4, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => array_key_first(SalesAssistantSetting::MODELS)];

        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form + ['holidays' => "2027-03-21 Navruz\nnext Friday"])
            ->assertSessionHasErrors('holidays');
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form + ['holidays' => "  2027-03-21 Navruz \r\n\r\n01.09.2027 Independence Day", 'morning_summary' => 0])
            ->assertSessionHasNoErrors();
        $s = SalesAssistantSetting::current()->refresh();
        $this->assertSame("2027-03-21 Navruz\n01.09.2027 Independence Day", $s->holidays);
        $this->assertFalse($s->morning_summary);
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('Independence Day')->assertSee('Morning summary');
    }

    public function test_morning_summary_lists_who_wrote_overnight_once(): void
    {
        Carbon::setTestNow($this->at('2027-03-22 09:01'));
        $s = $this->settings();
        $msg = fn (string $role, string $text, string $at, array $extra = []) => ['role' => $role, 'content' => $text, 'at' => $this->at($at)->toIso8601String()] + $extra;
        TelegramUser::create(['telegram_id' => '41', 'name' => 'Aziz Karimov', 'contact_type' => 'client']);
        TelegramUser::create(['telegram_id' => '43', 'name' => 'Our Staff', 'contact_type' => 'employee']);
        $c = fn (string $id, array $messages, bool $waiting, array $state = []) => ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => $id, 'locale' => 'ru',
            'messages' => $messages, 'sales_state' => $state ?: null])->forceFill(['awaiting_reply' => $waiting])->saveQuietly();

        // Friday evening → Monday morning.
        $c('41', [$msg('user', 'Day message', '2027-03-19 12:00'), $msg('user', 'Нужен принтер этикеток', '2027-03-19 21:00'), $msg('assistant', 'Конечно', '2027-03-19 21:01')], false, ['summary' => 'Label printer for a shop']);
        $c('42', [$msg('user', 'Salom, narx?', '2027-03-20 10:00'), $msg('assistant', 'Office closed', '2027-03-20 10:00', ['off_hours' => true])], true);
        $c('43', [$msg('user', 'staff note', '2027-03-20 11:00')], true);
        $c('44', [$msg('user', 'Before closing', '2027-03-19 17:00')], true);
        ChatLead::create(['name' => 'Aziz', 'phone' => '+998901234567', 'locale' => 'ru', 'channel' => 'telegram'])
            ->forceFill(['created_at' => $this->at('2027-03-19 21:05')->utc()])->saveQuietly();

        $summary = app(MorningSummary::class);
        $this->assertFalse($summary->due($s, $this->at('2027-03-20 10:00'))); // Saturday: closed
        $this->assertSame(2, $summary->run($s, $this->at('2027-03-22 09:01')));
        Http::assertSent(function (Request $r) {
            $t = (string) ($r['text'] ?? '');

            return str_contains($r->url(), '/sendMessage') && (string) $r['chat_id'] === '-100555'
                && str_contains($t, 'since 19.03 18:00') && str_contains($t, '2 customers wrote, 1 still waits') && str_contains($t, '1 new request')
                && str_contains($t, 'Aziz Karimov') && str_contains($t, 'Label printer for a shop') && str_contains($t, 'AI answered')
                && str_contains($t, 'Salom, narx?') && ! str_contains($t, 'staff note') && ! str_contains($t, 'Before closing') && ! str_contains($t, 'Day message')
                && strpos($t, 'Salom') < strpos($t, 'Aziz Karimov'); // still waiting first
        });

        // Once a day.
        $this->assertFalse($summary->due($s->refresh(), $this->at('2027-03-22 10:00')));
        $this->assertSame(0, $summary->run($s, $this->at('2027-03-22 10:00')));
        Http::assertSentCount(1);
        Carbon::setTestNow();
    }

    public function test_no_summary_when_nobody_wrote_or_switched_off(): void
    {
        $s = $this->settings(['morning_summary' => false]);
        $this->assertFalse(app(MorningSummary::class)->due($s, $this->at('2027-03-22 09:01')));
        $s->update(['morning_summary' => true]);
        $this->assertSame(0, app(MorningSummary::class)->run($s->refresh(), $this->at('2027-03-22 09:01')));
        Http::assertNothingSent();
        $this->assertSame('2027-03-22', $s->refresh()->morning_summary_sent_on->toDateString());
    }
}
