<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiSandboxChat;
use App\Models\AiShadowDraft;
use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistant;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** QA: edge cases of the outside-working-hours note. */
class SalesAssistantOffHoursQaTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public int $claudeCalls = 0;

    public bool $telegramFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on', 'off_hours_mode' => 'note']);
        $test = $this;
        Http::fake(['api.telegram.org/*' => fn () => $test->telegramFails
            ? Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)
            : Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private SalesAssistantOffHoursQaTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->claudeCalls++;

                return ['text' => json_encode(['reply' => 'Qaysi printer kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'questions',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false]),
                    'model' => $model, 'input_tokens' => 10, 'output_tokens' => 10];
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Tashkent'));
    }

    private function botUpdate(int $id, string $text, int $from = 7001): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(),
            'chat' => ['id' => $from, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => 'Ali', 'language_code' => 'uz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function businessUpdate(int $id, string $text, int $from = 7002): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $id, 'business_message' => ['text' => $text, 'message_id' => $id, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7002, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => $from === 7002 ? 'Vali' : 'Owner']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function toCustomer(string $chatId = '7001'): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())
            ->filter(fn ($m) => (string) ($m['chat_id'] ?? '') === $chatId)->values()->all();
    }

    private function conv(string $chatId, string $role, string $at, array $extra = []): ChatConversation
    {
        return new ChatConversation(['channel' => 'telegram', 'external_id' => $chatId, 'sales_state' => [],
            'messages' => [$extra + ['role' => $role, 'content' => 'x', 'at' => Carbon::parse($at, 'Asia/Tashkent')->toIso8601String()]]]);
    }

    public function test_boundaries_of_the_working_day(): void
    {
        $s = SalesAssistantSetting::current();
        $tz = 'Asia/Tashkent';
        $c = fn ($at) => $this->conv('7001', 'user', $at);
        $this->assertSame('now', SalesAssistant::decision($c('2026-11-24 17:59'), $s, Carbon::parse('2026-11-24 17:59:59', $tz)));
        $this->assertSame('closed', SalesAssistant::decision($c('2026-11-24 18:00'), $s, Carbon::parse('2026-11-24 18:00:00', $tz)));
        $this->assertSame('closed', SalesAssistant::decision($c('2026-11-24 08:59'), $s, Carbon::parse('2026-11-24 08:59:59', $tz)));
        $this->assertSame('now', SalesAssistant::decision($c('2026-11-24 09:00'), $s, Carbon::parse('2026-11-24 09:00:00', $tz)));

        $this->assertSame('2026-11-25 09:00', $s->nextOpening(Carbon::parse('2026-11-24 18:00', $tz))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-24 09:00', $s->nextOpening(Carbon::parse('2026-11-24 08:59:59', $tz))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-25 09:00', $s->nextOpening(Carbon::parse('2026-11-24 09:00:00', $tz))->format('Y-m-d H:i'), 'at opening: the next one');
        $this->assertSame('2026-11-30 09:00', $s->nextOpening(Carbon::parse('2026-11-28 12:00', $tz))->format('Y-m-d H:i'), 'Saturday → Monday');
        // nextOpening works from UTC input too.
        $this->assertSame('2026-11-25 09:00', $s->nextOpening(Carbon::parse('2026-11-24 15:00', 'UTC'))->format('Y-m-d H:i'));
    }

    public function test_working_days_with_gaps_and_a_single_day(): void
    {
        $s = SalesAssistantSetting::current();
        $s->update(['work_days' => [1, 3, 5]]);
        $s = $s->fresh();
        $tz = 'Asia/Tashkent';
        $this->assertSame('Mon, Wed, Fri 09:00–18:00', $s->hoursText('en'));
        $this->assertSame('2026-11-25 09:00', $s->nextOpening(Carbon::parse('2026-11-23 19:00', $tz))->format('Y-m-d H:i'));
        $this->assertStringContainsString('reply tomorrow from 09:00', $s->offHoursNote('en', Carbon::parse('2026-11-24 10:00', $tz)), 'Tuesday (day off) → Wednesday');
        $this->assertStringContainsString('reply on Monday from 09:00', $s->offHoursNote('en', Carbon::parse('2026-11-27 19:00', $tz)));
        $this->assertStringContainsString('ответит в понедельник с 09:00', $s->offHoursNote('ru', Carbon::parse('2026-11-27 19:00', $tz)));
        $this->assertStringContainsString('dushanba kuni soat 09:00', $s->offHoursNote('uz', Carbon::parse('2026-11-27 19:00', $tz)));

        $s->update(['work_days' => [1, 2, 4, 5, 6]]);
        $this->assertSame('Mon, Tue, Thu–Sat 09:00–18:00', $s->fresh()->hoursText('en'));

        $s->update(['work_days' => [1]]);
        $s = $s->fresh();
        $this->assertSame('2026-11-30 09:00', $s->nextOpening(Carbon::parse('2026-11-23 19:00', $tz))->format('Y-m-d H:i'), 'a week later');
        $this->assertStringContainsString('on Monday from 09:00', $s->offHoursNote('en', Carbon::parse('2026-11-23 19:00', $tz)));
    }

    public function test_settings_page_renders_and_saves_the_mode(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        SalesAssistantSetting::current()->update(['off_hours_mode' => null]);
        config(['services.telegram.sales_off_hours' => 'note']);
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()
            ->assertSee('<label for="off_hours_mode"', false)
            ->assertSee('<option value="note" selected', false)
            ->assertSee('Our office is closed right now');

        $valid = ['bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 2, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => 'claude-haiku-5-5', 'employee_ids' => ''];
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $valid + ['off_hours_mode' => 'silent'])->assertSessionHasNoErrors();
        $this->assertSame('silent', SalesAssistantSetting::current()->off_hours_mode);
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertSee('<option value="silent" selected', false);

        $this->actingAs($admin)->from(route('admin.sales-assistant.edit'))->put(route('admin.sales-assistant.update'), $valid + ['off_hours_mode' => 'bogus'])
            ->assertSessionHasErrors('off_hours_mode');
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $valid + ['off_hours_mode' => ''])->assertSessionHasErrors('off_hours_mode');
        $this->assertSame('silent', SalesAssistantSetting::current()->off_hours_mode);
        // Old input is kept after a failed save.
        $this->actingAs($admin)->withSession(['_old_input' => ['off_hours_mode' => 'ai']])->get(route('admin.sales-assistant.edit'))
            ->assertSee('<option value="ai" selected', false);

        $this->get(route('admin.sales-assistant.edit'))->assertOk(); // still signed in as admin
        auth()->logout();
        $this->put(route('admin.sales-assistant.update'), $valid + ['off_hours_mode' => 'ai'])->assertRedirect();
        $this->assertSame('silent', SalesAssistantSetting::current()->off_hours_mode);
    }

    public function test_employees_never_get_the_note(): void
    {
        $this->at('2026-11-24 20:00');
        TelegramUser::create(['telegram_id' => '7001', 'name' => 'Staff', 'is_employee' => true, 'locale' => 'uz']);
        $this->botUpdate(1, 'Salom');
        $this->assertSame([], $this->toCustomer());
        $this->assertSame(0, $this->claudeCalls);
    }

    public function test_a_shadow_employee_still_sends_the_fixed_note(): void
    {
        AiEmployee::where('kind', 'sales')->whereIn('status', AiEmployee::ACTIVE)->update(['status' => 'shadow']);
        $this->at('2026-11-24 20:00');
        $this->botUpdate(1, 'Salom');
        // Behaviour as implemented: the fixed note bypasses shadow mode (no draft, sent directly).
        $this->assertCount(1, $this->toCustomer());
        $this->assertSame(0, AiShadowDraft::count());
    }

    public function test_a_failed_send_is_not_retried_by_the_tick_and_not_marked_as_sent(): void
    {
        $this->at('2026-11-24 20:00');
        $this->telegramFails = true;
        $this->botUpdate(1, 'Salom');
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertArrayNotHasKey('off_hours_until', $c->sales_state ?? []);
        $this->assertSame(1, $c->sales_state['handled']);
        $this->assertNull(collect($c->messages)->firstWhere('off_hours', true));

        $this->telegramFails = false;
        $before = count($this->toCustomer());
        $this->artisan('sales:assistant-tick');
        $this->artisan('sales:assistant-tick');
        $this->assertCount($before, $this->toCustomer(), 'the tick does not retry a failed note');

        // The next customer message gets the note (once).
        $this->botUpdate(2, 'Salom?');
        $this->botUpdate(3, 'Salom??');
        $notes = collect(ChatConversation::where('channel', 'telegram')->sole()->messages)->where('off_hours', true);
        $this->assertCount(1, $notes);
    }

    public function test_after_the_night_note_the_owner_still_gets_wait_minutes_in_the_morning(): void
    {
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        SalesAssistantSetting::current()->update(['wait_minutes' => 10]);
        $this->at('2026-11-24 20:00');
        $this->businessUpdate(1, 'Salom');
        $this->assertCount(1, $this->toCustomer('7002'), 'the night note');

        // Morning: the customer writes again before the owner answered. The owner should get wait_minutes first,
        // as with any Premium-account chat the assistant is not talking in (the note is not a conversation).
        $this->at('2026-11-25 10:00');
        $this->businessUpdate(2, 'Javob bormi?');
        $this->assertSame(0, $this->claudeCalls, 'the assistant answered at once in the morning: the night note counted as "the assistant is already talking", so the owner got no wait_minutes');
        $this->assertCount(1, $this->toCustomer('7002'));
    }

    public function test_the_note_after_an_assistant_question_stops_reminder_and_unfinished_close(): void
    {
        $this->at('2026-11-24 17:30');
        $this->botUpdate(1, 'Printer kerak');
        $this->assertSame(1, $this->claudeCalls);
        $c = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertSame('questions', $c->sales_state['stage']);

        $this->at('2026-11-24 19:00');
        $this->botUpdate(2, 'Xprinter 58mm');
        $this->assertCount(2, $this->toCustomer());

        // No reminder after the note; far later what was collected goes to the managers as an unfinished request.
        $this->at('2026-11-25 10:00');
        $c->refresh();
        $this->assertNull(SalesAssistant::nudgeDue($c, SalesAssistantSetting::current()), 'no reminder after the note');
        $this->assertTrue($c->awaiting_reply, 'it stays with the managers');
        $this->at('2026-11-26 12:00');
        $this->assertSame('close', SalesAssistant::nudgeDue($c, SalesAssistantSetting::current()));
    }

    public function test_the_sandbox_still_calls_claude_at_night(): void
    {
        $this->at('2026-11-24 22:00');
        $admin = User::factory()->create(['is_admin' => true]);
        $malika = AiEmployee::where('kind', 'sales')->whereIn('status', AiEmployee::ACTIVE)->firstOrFail();
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.start'), ['ai_employee_id' => $malika->id, 'prompt' => 'published', 'via' => 'bot'])->assertRedirect();
        $chat = AiSandboxChat::sole();
        $this->actingAs($admin)->post(route('admin.ai-team.sandbox.message', $chat), ['message' => 'Salom'])->assertRedirect();
        $this->assertSame(1, $this->claudeCalls);
        $this->assertSame([], $this->toCustomer());
    }
}
