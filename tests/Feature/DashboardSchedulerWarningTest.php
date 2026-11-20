<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSchedulerWarningTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard()
    {
        return $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.dashboard'))->assertOk();
    }

    private function taskRun(string $task, string $status, $at): ScheduledTaskRun
    {
        return ScheduledTaskRun::create(['task' => $task, 'trigger' => 'schedule', 'status' => $status, 'started_at' => $at, 'finished_at' => $at]);
    }

    public function test_warns_when_cron_never_ran(): void
    {
        $this->dashboard()
            ->assertSee('data-scheduler-warning', false)
            ->assertSee('Cron is not set up')
            ->assertSee(route('admin.scheduled-tasks.index'));
    }

    public function test_warns_when_cron_stopped(): void
    {
        $this->taskRun('invoices', 'success', now()->subHours(2));

        $this->dashboard()
            ->assertSee('Cron has stopped')
            ->assertSee(now()->subHours(2)->format('d.m.Y H:i'))
            ->assertDontSee('Cron is not set up');
    }

    public function test_no_warning_while_cron_runs_and_tasks_succeed(): void
    {
        $this->taskRun('cvs', 'failed', now()->subHours(3));
        $this->taskRun('cvs', 'success', now()->subHour());
        $this->taskRun('invoices', 'success', now()->subMinute());

        $this->dashboard()->assertDontSee('data-scheduler-warning', false);
    }

    public function test_a_long_invoice_run_counts_as_cron_running(): void
    {
        ScheduledTaskRun::create(['task' => 'invoices', 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()->subMinutes(30)]);

        $this->dashboard()->assertDontSee('data-scheduler-warning', false);
    }

    public function test_lists_tasks_whose_last_run_failed(): void
    {
        $this->taskRun('invoices', 'success', now()->subMinute());
        $this->taskRun('ai-check', 'failed', now()->subHour());
        $this->taskRun('webhook', 'failed', now()->subHour());

        $this->dashboard()
            ->assertSee('data-scheduler-warning', false)
            ->assertDontSee('Cron has stopped')
            ->assertSee('Last run failed or did not finish: Check the AI models, Check the Telegram bot.');
        $this->assertSame(['Check the AI models', 'Check the Telegram bot'], ScheduledTasks::failedTasks());
    }

    public function test_a_stuck_latest_run_counts_as_failed(): void
    {
        $this->taskRun('invoices', 'success', now()->subMinute());
        ScheduledTaskRun::create(['task' => 'prune', 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()->subHours(2)]);
        ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()->subMinute()]);

        $this->dashboard()->assertSee('Last run failed or did not finish: Delete old records.')->assertSee('(', false);
        $this->assertSame(1, ScheduledTasks::failedCount());
    }

    public function test_manual_runs_do_not_count_as_cron(): void
    {
        ScheduledTaskRun::create(['task' => 'sitemap', 'trigger' => 'manual', 'status' => 'success', 'started_at' => now(), 'finished_at' => now()]);

        $this->dashboard()->assertSee('Cron is not set up');
    }
}
