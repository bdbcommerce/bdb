<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
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

    public function test_every_task_with_a_cron_is_scheduled_with_its_expression(): void
    {
        foreach (ScheduledTasks::all() as $key => $task) {
            $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains($e->command, " {$task['command']}")
                && str_contains($e->command, '--daily') === isset($task['parameters']['--daily'])
                && ($task['parameters'] === [] || isset($task['parameters']['--daily']) || str_contains($e->command, '"'.($task['parameters']['action'] ?? '').'"') || isset($task['parameters']['--model'])));
            if ($task['cron'] === null) {
                $this->assertFalse(collect(app(Schedule::class)->events())->contains(fn (Event $e) => str_contains($e->command, 'telegram:webhook "set"')), 'by hand only');

                continue;
            }
            $this->assertNotNull($event, $key);
            $this->assertSame($task['cron'], $event->expression, $key);
        }

        $this->assertTrue($this->event('invoices')->runInBackground);
        $this->assertTrue($this->event('invoices')->withoutOverlapping);
        $this->assertStringContainsString('telegram:webhook "info"', $this->event('webhook')->command);
        $this->assertStringContainsString('ScheduledTaskRun', $this->event('prune')->command);
    }

    public function test_a_scheduled_run_is_recorded_with_its_output(): void
    {
        $event = $this->event('sitemap');
        $event->callBeforeCallbacks($this->app);
        $run = ScheduledTaskRun::sole();
        $this->assertSame(['sitemap', 'schedule', 'running'], [$run->task, $run->trigger, $run->state()]);

        file_put_contents(ScheduledTasks::logPath('sitemap'), "\e[32msitemap.xml rebuilt with 12 URLs\e[0m\n");
        try {
            $event->finish($this->app, 0);
        } finally {
            @unlink(ScheduledTasks::logPath('sitemap'));
        }

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertSame('sitemap.xml rebuilt with 12 URLs', $run->output, 'colour codes removed');
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
    }

    public function test_a_failed_scheduled_run_alerts_once(): void
    {
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.alert_chat_id' => '-100500']);
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });

        foreach ([1, 2] as $i) {
            $event = $this->event('ai-check');
            $event->callBeforeCallbacks($this->app);
            $event->finish($this->app, 1);
        }

        $this->assertSame(2, ScheduledTaskRun::where('status', 'failed')->count());
        $this->assertCount(1, $sent, 'repeats are muted');
        $this->assertSame('-100500', (string) $sent[0]['chat_id']);
        $this->assertStringContainsString('Scheduled task failed', $sent[0]['text']);
        $this->assertStringContainsString('Check the AI models', $sent[0]['text']);
        $this->assertSame(1, ScheduledTasks::failedCount());
    }

    public function test_a_background_finish_without_a_start_still_records(): void
    {
        ScheduledTasks::finished('invoices', 0);
        $this->assertSame('success', ScheduledTaskRun::sole()->status);
    }

    public function test_a_new_start_closes_a_run_that_never_reported(): void
    {
        $old = ScheduledTasks::started('invoices');
        $new = ScheduledTasks::started('invoices');
        ScheduledTasks::finished('invoices', 0);

        $this->assertSame('failed', $old->fresh()->status);
        $this->assertStringContainsString('Did not finish', $old->fresh()->output);
        $this->assertSame('success', $new->fresh()->status);
        $this->assertSame(110, $this->event('invoices')->expiresAt, 'no overlap while a long run may still go');
    }

    public function test_bot_check_fails_when_the_webhook_is_not_connected(): void
    {
        config(['services.telegram.bot_token' => '123:abc']);
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'bdb_bot']]),
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]]),
        ]);

        $this->artisan('telegram:webhook', ['action' => 'info'])->assertFailed();
    }

    public function test_page_lists_tasks_and_warns_when_cron_is_not_running(): void
    {
        $this->actingAs($this->admin())->get(route('admin.scheduled-tasks.index'))
            ->assertOk()
            ->assertSee('data-scheduler="stopped"', false)
            ->assertSee('schedule:run')
            ->assertSee('Read invoice photos')
            ->assertSee('Reconnect the Telegram bot')
            ->assertSee('php artisan telegram:webhook info')
            ->assertSee('Never ran');

        ScheduledTasks::started('invoices');
        ScheduledTaskRun::create(['task' => 'cvs', 'trigger' => 'schedule', 'status' => 'failed', 'exit_code' => 1,
            'started_at' => now()->subHour(), 'finished_at' => now()->subHour(), 'duration_ms' => 1500, 'output' => 'Telegram <b>down</b>']);

        $this->actingAs($this->admin())->get(route('admin.scheduled-tasks.index'))
            ->assertOk()
            ->assertSee('data-scheduler="running"', false)
            ->assertSee('Failed')
            ->assertSee('Telegram &lt;b&gt;down&lt;/b&gt;', false)
            ->assertSee('1.5 s');
    }

    public function test_menu_shows_the_page(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee(route('admin.scheduled-tasks.index'));
    }

    public function test_run_now_runs_the_command_and_records_it(): void
    {
        $this->withoutDefer();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.scheduled-tasks.run', 'home-cache'))
            ->assertRedirect(route('admin.scheduled-tasks.index'))
            ->assertSessionHas('success');

        $run = ScheduledTaskRun::sole();
        $this->assertSame(['manual', 'success', $admin->id], [$run->trigger, $run->status, $run->user_id]);
        $this->assertStringContainsString('Home page cache cleared', $run->output);
        $this->assertTrue(Cache::lock('scheduled-task-run:home-cache', 1)->get(), 'lock released');
    }

    public function test_run_now_records_a_failing_command(): void
    {
        $this->withoutDefer();
        config(['services.telegram.bot_token' => null]);

        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'webhook'))->assertSessionHas('success');

        $this->assertSame('failed', ScheduledTaskRun::sole()->status);
    }

    public function test_run_now_is_refused_while_the_task_runs(): void
    {
        Cache::lock('scheduled-task-run:sitemap', 60)->get();

        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'sitemap'))->assertSessionHasErrors('task');
        $this->assertSame(0, ScheduledTaskRun::count());
    }

    public function test_run_now_waits_for_a_scheduled_cv_run(): void
    {
        ScheduledTasks::started('cvs');

        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'cvs'))->assertSessionHasErrors('task');
        $this->assertSame(1, ScheduledTaskRun::count());
    }

    public function test_access_control_and_unknown_tasks(): void
    {
        $this->post(route('admin.scheduled-tasks.run', 'sitemap'))->assertRedirect();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.scheduled-tasks.index'))->assertRedirect();
        $this->actingAs($this->admin())->post(route('admin.scheduled-tasks.run', 'nope'))->assertNotFound();
        $this->assertSame(0, ScheduledTaskRun::count());
    }

    public function test_long_running_rows_show_as_stuck_and_old_runs_are_pruned(): void
    {
        $stuck = ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()->subHour()]);
        $this->assertSame('stuck', $stuck->state());
        $this->assertFalse(ScheduledTasks::schedulerRunning());

        $old = ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'schedule', 'status' => 'success', 'started_at' => now()->subDays(ScheduledTaskRun::KEEP_DAYS + 1)]);
        $this->artisan('model:prune', ['--model' => [ScheduledTaskRun::class]])->assertSuccessful();
        $this->assertModelMissing($old);
        $this->assertModelExists($stuck);
    }
}
