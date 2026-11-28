<?php

namespace Tests\Feature;

use App\Models\AiShadowDraft;
use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\Client;
use App\Models\CompanyLink;
use App\Models\ExpertQuestion;
use App\Models\Order;
use App\Models\SalesAssistantSetting;
use App\Models\SalesReport;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\SalesReport\SalesMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Walk-through step 8: measured numbers of a report period, the Telegram message and the admin page. */
class SalesMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $tashkent): Carbon
    {
        return Carbon::parse($tashkent, SalesAssistantSetting::TZ);
    }

    /** Created rows get this UTC time (the columns are UTC). */
    private function stamp($model, string $tashkent, string $column = 'created_at')
    {
        $model->forceFill([$column => $this->at($tashkent)->utc()])->saveQuietly();

        return $model;
    }

    public function test_the_week_is_measured_from_the_database(): void
    {
        Carbon::setTestNow($this->at('2027-03-19 20:00')); // rows made "now" are inside the week
        SalesAssistantSetting::current()->update(['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00', 'holidays' => '2027-03-17 Holiday']);
        $from = $this->at('2027-03-13 09:00');
        $to = $this->at('2027-03-20 09:00');
        $msg = fn (string $role, string $at, array $extra = []) => ['role' => $role, 'content' => 'x', 'at' => $this->at($at)->toIso8601String()] + $extra;
        $chat = fn (string $id, array $messages) => ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => $id, 'locale' => 'ru', 'messages' => $messages]);

        // Off hours (Tue 22:00) answered by the AI; a working-hours AI reply; a hand-over; a holiday message with the note.
        $a = $chat('41', [$msg('user', '2027-03-16 22:00'), $msg('assistant', '2027-03-16 22:01'), $msg('user', '2027-03-18 10:00'),
            $msg('assistant', '2027-03-18 10:01'), $msg('assistant', '2027-03-18 10:02', ['handoff' => true])]);
        $chat('42', [$msg('user', '2027-03-17 11:00'), $msg('assistant', '2027-03-17 11:00', ['off_hours' => true]), $msg('user', '2027-03-10 11:00')]);
        // Outside the period.
        $chat('43', [$msg('user', '2027-03-12 22:00'), $msg('assistant', '2027-03-12 22:01')]);
        // Waiting since Thursday at the end of the week.
        ChatConversation::whereKey($a->id)->update(['awaiting_reply' => true, 'last_message_at' => $this->at('2027-03-19 12:00')->utc()]);

        AiShadowDraft::create(['chat_conversation_id' => $a->id, 'draft' => 'd1', 'status' => 'sent']);
        AiShadowDraft::create(['chat_conversation_id' => $a->id, 'draft' => 'd2', 'status' => 'discarded']);
        $q = ExpertQuestion::create(['chat_conversation_id' => $a->id, 'question' => 'Q?', 'status' => 'answered']);
        $this->stamp($q, '2027-03-18 10:00');
        $this->stamp($q, '2027-03-18 10:40', 'answered_at');
        $user = TelegramUser::create(['telegram_id' => '41', 'name' => 'Aziz']);
        $o = Order::create(['telegram_user_id' => $user->id, 'status' => 'confirmed', 'items' => [['product' => 'Zebra DS2208'], ['product' => 'Ribbon']], 'quoted' => [['price' => '1 150 000'], ['price' => '50 000']]]);
        $this->stamp($o, '2027-03-18 12:00', 'confirmed_at');
        Order::create(['telegram_user_id' => $user->id, 'status' => 'draft', 'items' => []]);
        $client = Client::create(['name' => 'Demo LLC']);
        CompanyLink::create(['client_id' => $client->id, 'telegram_user_id' => $user->id, 'linked_by' => 'ai']);
        CompanyLink::create(['client_id' => $client->id, 'group_chat_id' => '-100', 'linked_by' => 'manager']);
        ChatLead::create(['channel' => 'telegram', 'name' => 'Aziz', 'phone' => '+998901234567', 'locale' => 'ru', 'taken_by' => 'Dilnoza', 'taken_at' => now()]);
        ChatLead::create(['channel' => 'business', 'name' => 'Bek', 'phone' => '+998901234568', 'locale' => 'ru']);

        $m = app(SalesMetrics::class)->measure($from, $to, ['Staff: Dilnoza' => [60, 600, 120]]);

        $this->assertSame(['chats' => 1, 'replies' => 2, 'handovers' => 1, 'leads' => 2], array_intersect_key($m['ai'], array_flip(['chats', 'replies', 'handovers', 'leads'])));
        $this->assertSame(['sent' => 1, 'discarded' => 1], $m['ai']['shadow']);
        $this->assertSame(['asked' => 1, 'answered' => 1, 'avg_answer_minutes' => 40], $m['ai']['experts']);
        $this->assertSame([1, 1200000, 1, 0], [$m['orders']['confirmed'], $m['orders']['confirmed_sum'], $m['orders']['drafts'], $m['orders']['cancelled']]);
        $this->assertSame(['Zebra DS2208' => 1, 'Ribbon' => 1], $m['orders']['top_products']);
        $this->assertSame(['linked_by_ai' => 1, 'linked_by_manager' => 1, 'new_clients' => 1], $m['companies']);
        $this->assertSame([['name' => 'Dilnoza', 'replies' => 3, 'avg_seconds' => 260, 'median_seconds' => 120]], $m['managers']['people']);
        $this->assertSame([1, 1, 2, ['Dilnoza' => 1]], // chat 42 too: the off-hours note is no answer
            [$m['managers']['leads_taken'], $m['managers']['leads_not_taken'], $m['managers']['waiting_over_hour'], $m['managers']['taken_by']]);
        $this->assertSame(['messages' => 2, 'customers' => 2, 'answered_by_ai' => 1, 'holiday_messages' => 1, 'notes' => 1], $m['off_hours']);

        $text = app(SalesMetrics::class)->telegram($m, 'en');
        $this->assertStringContainsString('🤖 <b>AI team</b>: 1 chats · 2 replies · 1 hand-overs · 2 requests', $text);
        $this->assertStringContainsString('1 confirmed ≈ 1 200 000 sum', $text);
        $this->assertStringContainsString('Dilnoza: 3 · first reply ~4 min', $text);
        $this->assertStringContainsString('🌙 <b>Off hours</b>: 2 messages, 2 customers · answered by the AI 1 · 1 on holidays', $text);
        $this->assertStringContainsString('<b>Цифры</b>', app(SalesMetrics::class)->telegram($m, 'ru'));
        Carbon::setTestNow();
    }

    public function test_the_admin_page_shows_the_numbers_and_the_change(): void
    {
        $base = ['ai' => ['chats' => 4, 'replies' => 10, 'handovers' => 0, 'leads' => 2, 'shadow' => [], 'experts' => ['asked' => 0, 'answered' => 0, 'avg_answer_minutes' => null]],
            'orders' => ['confirmed' => 2, 'confirmed_sum' => 1000000, 'drafts' => 1, 'cancelled' => 0, 'top_products' => ['<b>Printer</b>' => 2]],
            'companies' => ['linked_by_ai' => 0, 'linked_by_manager' => 1, 'new_clients' => 0],
            'managers' => ['people' => [['name' => '<i>Ann</i>', 'replies' => 2, 'avg_seconds' => 300, 'median_seconds' => 300]], 'leads_taken' => 1, 'leads_not_taken' => 1, 'taken_by' => [], 'waiting_over_hour' => 2],
            'off_hours' => ['messages' => 3, 'customers' => 2, 'answered_by_ai' => 2, 'holiday_messages' => 0, 'notes' => 0]];
        $old = SalesReport::create(['period' => 'week', 'period_start' => now()->subDays(14), 'period_end' => now()->subDays(7), 'trigger' => 'schedule', 'status' => 'sent', 'metrics' => $base]);
        $new = $base;
        $new['ai']['chats'] = 6;
        $new['managers']['waiting_over_hour'] = 5;
        $report = SalesReport::create(['period' => 'week', 'period_start' => now()->subDays(7), 'period_end' => now(), 'trigger' => 'schedule', 'status' => 'empty', 'metrics' => $new]);
        SalesReport::create(['period' => 'day', 'period_start' => now()->subDays(8), 'period_end' => now()->subDays(7), 'trigger' => 'schedule', 'status' => 'sent', 'metrics' => $base]);
        $this->assertTrue($report->previous()->is($old));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.sales-report.show', $report))->assertOk()
            ->assertSee('Chats the AI answered')->assertSee('▲ +2 (+50%)')->assertSee('▲ +3 (+150%)')
            ->assertSee('&lt;b&gt;Printer&lt;/b&gt; ×2', false)->assertSee('&lt;i&gt;Ann&lt;/i&gt;', false)->assertDontSee('<b>Printer', false);
        $this->actingAs($admin)->get(route('admin.sales-report.index'))->assertOk()->assertSee(route('admin.sales-report.show', $report));
        $this->actingAs($admin)->get(route('admin.sales-report.show', $old))->assertOk()->assertSee('No earlier report to compare with');
    }
}
