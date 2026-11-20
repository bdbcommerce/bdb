<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardSchedulerWarningQaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function taskRun(string $task, string $status, $at, string $trigger = 'schedule'): ScheduledTaskRun
    {
        return ScheduledTaskRun::create(['task' => $task, 'trigger' => $trigger, 'status' => $status, 'started_at' => $at, 'finished_at' => $status === 'running' ? null : $at]);
    }

    public function test_guest_redirected_and_non_admin_refused(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect();
        $resp = $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.dashboard'));
        $this->assertNotSame(200, $resp->status());
        $resp->assertDontSee('data-scheduler-warning', false);
    }

    public function test_missing_table_does_not_break_dashboard(): void
    {
        Schema::drop('scheduled_task_runs');

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-scheduler-warning', false);
    }

    public function test_cron_stopped_and_failed_shown_together(): void
    {
        $this->taskRun('cvs', 'failed', now()->subHours(5));

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Cron has stopped')
            ->assertSee('Last run failed or did not finish: Send CVs to Telegram.')
            ->assertSee('border-amber-200', false);
    }

    public function test_run_just_older_than_three_minutes_means_stopped(): void
    {
        $this->taskRun('invoices', 'success', now()->subMinutes(4));

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee('Cron has stopped');
    }

    public function test_stuck_running_row_does_not_count_as_cron_running(): void
    {
        // invoices max_minutes = 110: a "running" row 3 h old is stuck (killed process).
        $this->taskRun('invoices', 'running', now()->subHours(3));

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee('Cron has stopped');
    }

    public function test_recent_manual_run_alone_does_not_count_as_cron(): void
    {
        $this->taskRun('invoices', 'running', now()->subMinute(), 'manual');
        $this->taskRun('cvs', 'success', now()->subHours(2));

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee('Cron has stopped');
    }

    public function test_unknown_task_rows_are_ignored(): void
    {
        $this->taskRun('invoices', 'success', now()->subMinute());
        $this->taskRun('removed-task', 'failed', now()->subMinute());

        $this->assertSame([], ScheduledTasks::failedTasks());
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertDontSee('data-scheduler-warning', false);
    }

    public function test_later_success_clears_failure_and_order_is_by_id(): void
    {
        $this->taskRun('invoices', 'success', now()->subMinute());
        $this->taskRun('sitemap', 'failed', now()->subDay());
        $this->taskRun('sitemap', 'success', now()->subHours(2));

        $this->assertSame([], ScheduledTasks::failedTasks());
    }

    public function test_failed_manual_run_as_latest_counts_as_failed(): void
    {
        // Documents current behaviour: "latest run" includes "Run now" runs (same as the menu badge).
        $this->taskRun('invoices', 'success', now()->subMinute());
        $this->taskRun('sitemap', 'failed', now(), 'manual');

        $this->assertSame(['Rebuild sitemap.xml'], ScheduledTasks::failedTasks());
        $this->assertSame(1, ScheduledTasks::failedCount());
    }

    public function test_failed_list_matches_menu_badge_count(): void
    {
        $this->taskRun('invoices', 'success', now()->subMinute());
        foreach (['cvs', 'webhook', 'prune'] as $k) {
            $this->taskRun($k, 'failed', now()->subHour());
        }

        $this->assertCount(ScheduledTasks::failedCount(), ScheduledTasks::failedTasks());
        $this->assertSame(['Check the Telegram bot', 'Delete old records', 'Send CVs to Telegram'], ScheduledTasks::failedTasks());
    }

    public function test_last_run_time_uses_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tashkent']);
        date_default_timezone_set('Asia/Tashkent');
        $at = now()->subHours(2);
        $this->taskRun('cvs', 'success', $at);

        $this->assertSame($at->format('d.m.Y H:i'), ScheduledTasks::lastSchedulerRun()->format('d.m.Y H:i'));
        date_default_timezone_set('UTC');
    }
}
