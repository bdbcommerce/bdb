<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Invoices\InvoiceParser;
use App\Services\Scheduling\ScheduledTasks;
use App\Services\Seo\SeoFiles;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduledTasksQaTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $key): Event
    {
        $command = ScheduledTasks::get($key)['command'];

        return collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command, " {$command}"));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_schedule_finish_command_closes_the_background_run(): void
    {
        $event = $this->event('invoices');
        $event->callBeforeCallbacks($this->app);
        $run = ScheduledTaskRun::sole();

        // What the background shell line runs after invoices:process exits.
        $this->artisan('schedule:finish', ['id' => $event->mutexName(), 'code' => 3])->assertSuccessful();

        $run->refresh();
        $this->assertSame(['failed', 3], [$run->status, $run->exit_code]);
        $this->assertSame(1, ScheduledTaskRun::count(), 'no extra row');
    }

    public function test_overlap_mutex_skips_the_invoice_task(): void
    {
        $event = $this->event('invoices');
        $this->assertTrue($event->mutex->create($event));
        $this->assertTrue($event->shouldSkipDueToOverlapping());
        $this->assertFalse($event->filtersPass($this->app));
        $this->assertSame(0, ScheduledTaskRun::count());
        $event->mutex->forget($event);
    }

    public function test_every_registered_event_has_output_file_and_callbacks(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertCount(collect(ScheduledTasks::all())->whereNotNull('cron')->count(), $events);
        foreach ($events as $e) {
            $this->assertStringContainsString('schedule-', (string) $e->output);
        }
        $this->assertTrue($this->event('cvs')->withoutOverlapping);
        $this->assertFalse($this->event('cvs')->runInBackground);
    }

    public function test_failed_count_uses_only_the_latest_run_per_known_task(): void
    {
        $at = now()->subHour();
        ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'schedule', 'status' => 'failed', 'started_at' => $at]);
        ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'schedule', 'status' => 'success', 'started_at' => $at->copy()->addMinute()]);
        ScheduledTaskRun::create(['task' => 'cvs', 'trigger' => 'schedule', 'status' => 'failed', 'started_at' => $at]);
        ScheduledTaskRun::create(['task' => 'removed-task', 'trigger' => 'schedule', 'status' => 'failed', 'started_at' => $at]);

        $this->assertSame(1, ScheduledTasks::failedCount());

        $sql = ScheduledTaskRun::whereIn('id',
            ScheduledTaskRun::selectRaw('max(id)')->whereIn('task', ['a'])->groupBy('task')
        )->where('status', 'failed')->toSql();
        $this->assertStringNotContainsStringIgnoringCase('limit', $sql, 'MySQL rejects LIMIT inside IN subqueries');
    }

    public function test_output_is_escaped_in_pre_and_title(): void
    {
        ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'schedule', 'status' => 'failed', 'exit_code' => 1,
            'started_at' => now()->subMinutes(5), 'finished_at' => now(), 'duration_ms' => 10,
            'output' => '"><script>alert(1)</script><img src=x onerror=alert(2)>']);

        $html = $this->actingAs($this->admin())->get(route('admin.scheduled-tasks.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_long_cyrillic_and_invalid_utf8_output_is_trimmed_and_saved(): void
    {
        $event = $this->event('sitemap');
        $event->callBeforeCallbacks($this->app);
        file_put_contents(ScheduledTasks::logPath('sitemap'), str_repeat('Ўзбекистон бозори ', 1000)."\xC3\x28 end");
        try {
            $event->finish($this->app, 0);
        } finally {
            @unlink(ScheduledTasks::logPath('sitemap'));
        }

        $run = ScheduledTaskRun::sole();
        $this->assertSame('success', $run->status);
        $this->assertLessThanOrEqual(ScheduledTasks::OUTPUT_MAX + 1, mb_strlen($run->output));
        $this->assertTrue(mb_check_encoding($run->output, 'UTF-8'));
        $this->assertStringStartsWith('…', $run->output);

        $this->actingAs($this->admin())->get(route('admin.scheduled-tasks.index'))->assertOk();
    }

    public function test_alert_masks_the_bot_token_in_output(): void
    {
        config(['services.telegram.bot_token' => '123456:SECRETTOKEN', 'services.telegram.alert_chat_id' => '-1']);
        $sent = [];
        Http::fake(function (Request $r) use (&$sent) {
            $sent[] = $r->data();

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        $event = $this->event('webhook');
        $event->callBeforeCallbacks($this->app);
        file_put_contents(ScheduledTasks::logPath('webhook'), 'error at https://api.telegram.org/bot123456:SECRETTOKEN/getWebhookInfo <x>');
        try {
            $event->finish($this->app, 1);
        } finally {
            @unlink(ScheduledTasks::logPath('webhook'));
        }

        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('SECRETTOKEN', $sent[0]['text']);
        $this->assertStringContainsString('&lt;x&gt;', $sent[0]['text']);
        $this->assertSame('HTML', $sent[0]['parse_mode']);
    }

    public function test_manual_failure_does_not_alert(): void
    {
        $this->withoutDefer();
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'webhook'));

        Http::assertNothingSent();
        $this->assertSame('failed', ScheduledTaskRun::sole()->status);
    }

    public function test_run_now_lock_is_released_when_the_command_throws(): void
    {
        $this->withoutDefer();
        // prune with a model list fine; force a throw by an unknown command via the registry is not possible,
        // so use sitemap and make it throw through a broken binding.
        $this->app->bind(SeoFiles::class, fn () => throw new \RuntimeException('boom <b>'));

        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'sitemap'))->assertSessionHas('success');

        $run = ScheduledTaskRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('boom', $run->output);
        $this->assertTrue(Cache::lock('scheduled-task-run:sitemap', 1)->get(), 'lock released');
    }

    public function test_get_on_run_route_and_non_admin_post_are_refused(): void
    {
        $this->actingAs($this->admin())->get('/admin/scheduled-tasks/sitemap/run')->assertStatus(405);
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('admin.scheduled-tasks.run', 'sitemap'))->assertRedirect();
        $this->assertSame(0, ScheduledTaskRun::count());
    }

    public function test_guest_is_redirected_from_index(): void
    {
        $this->get(route('admin.scheduled-tasks.index'))->assertRedirect();
    }

    public function test_stuck_invoice_run_after_max_minutes(): void
    {
        $r = ScheduledTaskRun::create(['task' => 'invoices', 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()->subMinutes(100)]);
        $this->assertSame('running', $r->state());
        $this->assertTrue(ScheduledTasks::schedulerRunning());

        $r->update(['started_at' => now()->subMinutes(111)]);
        $this->assertSame('stuck', $r->fresh()->state());
        $this->assertFalse(ScheduledTasks::schedulerRunning());

        $this->actingAs($this->admin())->get(route('admin.scheduled-tasks.index'))->assertOk()->assertSee('Did not finish');
    }

    /** Documents a side effect: a manual run of the invoice task sets the cron heartbeat. */
    public function test_manual_invoice_run_does_not_set_the_cron_heartbeat(): void
    {
        $this->withoutDefer();
        Cache::forget(InvoiceParser::HEARTBEAT);

        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'invoices'))->assertSessionHas('success');

        $this->assertFalse(InvoiceParser::schedulerRunning(), 'the webhook keeps reading photos itself');
        $this->assertFalse(ScheduledTasks::schedulerRunning(), 'the Scheduled tasks page is not fooled');
    }
}
