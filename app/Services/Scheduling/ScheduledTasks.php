<?php

namespace App\Services\Scheduling;

use App\Models\ChatConversation;
use App\Models\JobApplication;
use App\Models\LinkClick;
use App\Models\PageView;
use App\Models\SalesReport;
use App\Models\ScheduledTaskRun;
use App\Services\AiErrorAlerts;
use App\Services\Telegram\TelegramApi;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Every background task of the site, in one place: registered with the Laravel scheduler (routes/console.php,
 * needs the `schedule:run` cron), listed in Admin → Scheduled tasks with their runs, and runnable from there.
 * cron null = run by hand only.
 */
final class ScheduledTasks
{
    public const OUTPUT_MAX = 4000;

    /** A failure alert for the same task is sent at most once in this many hours. */
    public const ALERT_HOURS = 6;

    public static function all(): array
    {
        return [
            'invoices' => [
                'label' => 'Read invoice photos',
                'description' => 'Invoice parser: reads waiting photos with Claude and retries failed ones.',
                'command' => 'invoices:process', 'parameters' => [],
                'cron' => '* * * * *', 'every' => 'Every minute',
                'overlap' => 110, 'background' => true, 'max_minutes' => 110,
                // By hand: no cron heartbeat, so the webhook keeps reading photos itself when cron is missing.
                'manual_parameters' => ['--manual' => true],
            ],
            'cvs' => [
                'label' => 'Send CVs to Telegram',
                'description' => 'Retries CVs whose upload to the admin Telegram chat failed, then deletes them from the server.',
                'command' => 'careers:send-cvs', 'parameters' => [],
                'cron' => '0 * * * *', 'every' => 'Every hour', 'overlap' => 1440, 'exclusive' => true, 'max_minutes' => 60,
            ],
            'webhook' => [
                'label' => 'Check the Telegram bot',
                'description' => 'Asks Telegram whether the bot webhook is connected, and shows pending updates and the last error.',
                'command' => 'telegram:webhook', 'parameters' => ['action' => 'info'],
                'cron' => '5 * * * *', 'every' => 'Every hour',
            ],
            'ai-check' => [
                'label' => 'Check the AI models',
                'description' => 'Sends one short test question to every active AI model (Admin → AI settings). Fails when none answers.',
                'command' => 'ai:check', 'parameters' => [],
                'cron' => '0 8 * * *', 'every' => 'Daily at 08:00',
            ],
            'sitemap' => [
                'label' => 'Rebuild sitemap.xml',
                'description' => 'Rebuilds the live /sitemap.xml from the current content.',
                'command' => 'sitemap:generate', 'parameters' => [],
                'cron' => '30 3 * * *', 'every' => 'Daily at 03:30',
            ],
            'home-cache' => [
                'label' => 'Refresh the home page cache',
                'description' => 'Clears the cached home page, so the next visit builds a fresh copy.',
                'command' => 'site:clear-home-cache', 'parameters' => [],
                'cron' => '0 4 * * *', 'every' => 'Daily at 04:00',
            ],
            'sales-report' => [
                'label' => 'Weekly sales report',
                'description' => 'Claude Opus 5.5 analyses the last 7 days of Telegram sales chats (leads, orders, results, team rating) and posts one message per conversation plus a summary to the invoice parser\'s source group.',
                'command' => 'sales:weekly-report', 'parameters' => [],
                'cron' => '0 9 * * 6', 'timezone' => 'Asia/Tashkent', 'every' => 'Saturday 09:00 (Tashkent)',
                'overlap' => 120, 'background' => true, 'exclusive' => true, 'max_minutes' => 120,
                'manual_parameters' => ['--manual' => true],
            ],
            'prune' => [
                'label' => 'Delete old records',
                'description' => 'Deletes conversations after 90 days, CVs and sales reports after 365 days, analytics after 400 days and task runs after '.ScheduledTaskRun::KEEP_DAYS.' days.',
                'command' => 'model:prune',
                'parameters' => ['--model' => [ChatConversation::class, JobApplication::class, PageView::class, LinkClick::class, ScheduledTaskRun::class, SalesReport::class]],
                'cron' => '0 0 * * *', 'every' => 'Daily at 00:00', 'max_minutes' => 30,
            ],
            'webhook-connect' => [
                'label' => 'Reconnect the Telegram bot',
                'description' => 'Sets the bot webhook to this site again (after a domain change, or when the bot stops getting messages).',
                'command' => 'telegram:webhook', 'parameters' => ['action' => 'set'],
                'cron' => null, 'every' => 'By hand only',
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** After this long a run still marked "running" is shown as stuck (killed or crashed). */
    public static function maxMinutes(string $key): int
    {
        return (int) (self::get($key)['max_minutes'] ?? 15);
    }

    public static function logPath(string $key): string
    {
        return storage_path("logs/schedule-{$key}.log");
    }

    /** Called from routes/console.php. */
    public static function register(Schedule $schedule): void
    {
        foreach (self::all() as $key => $task) {
            if ($task['cron'] === null) {
                continue;
            }

            $event = $schedule->command($task['command'], self::cliParameters($task['parameters']))
                ->cron($task['cron'])
                ->timezone($task['timezone'] ?? config('app.timezone'))
                ->description($task['label'])
                ->sendOutputTo(self::logPath($key));
            if (isset($task['overlap'])) {
                $event->withoutOverlapping($task['overlap']);
            }
            if ($task['background'] ?? false) {
                // A run can wait minutes on an API and must not hold up the other tasks.
                $event->runInBackground();
            }

            // before: in schedule:run. after: in schedule:run, or in schedule:finish for background tasks.
            $event->before(fn () => rescue(fn () => self::started($key), report: true))
                ->after(fn () => rescue(fn () => self::finished($key, $event->exitCode), report: true));
        }
    }

    public static function started(string $key): ScheduledTaskRun
    {
        // Tasks never overlap (mutex), so a run still open from earlier was killed or crashed.
        ScheduledTaskRun::where('task', $key)->where('trigger', 'schedule')->where('status', 'running')
            ->update(['status' => 'failed', 'finished_at' => now(), 'output' => 'Did not finish: the process ended without reporting a result.']);

        return ScheduledTaskRun::create(['task' => $key, 'trigger' => 'schedule', 'status' => 'running', 'started_at' => now()]);
    }

    public static function finished(string $key, ?int $exitCode): ScheduledTaskRun
    {
        $run = ScheduledTaskRun::where('task', $key)->where('trigger', 'schedule')->where('status', 'running')
            ->latest('started_at')->latest('id')->first()
            ?? new ScheduledTaskRun(['task' => $key, 'trigger' => 'schedule', 'started_at' => now()]);

        $output = is_file(self::logPath($key)) ? (string) @file_get_contents(self::logPath($key), false, null, 0, 200_000) : '';
        self::complete($run, $exitCode ?? 1, $output);

        return $run;
    }

    /** "Run now": null when started (it runs after the response), else why not. */
    public static function runNow(string $key, ?int $userId): ?string
    {
        $task = self::get($key);
        if (! $task) {
            return 'Unknown task.';
        }

        $lock = Cache::lock("scheduled-task-run:{$key}", self::maxMinutes($key) * 60);
        if (! $lock->get()) {
            return 'This task is already running — wait until it finishes.';
        }
        if (($task['exclusive'] ?? false) && self::latest($key)?->state() === 'running') {
            $lock->release();

            return 'The scheduler is running this task right now — wait until it finishes.';
        }

        $run = ScheduledTaskRun::create(['task' => $key, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now(), 'user_id' => $userId]);

        defer(function () use ($task, $run, $lock) {
            @set_time_limit(0);
            $buffer = new BufferedOutput;
            try {
                $code = Artisan::call($task['command'], ($task['manual_parameters'] ?? []) + $task['parameters'], $buffer);
                $output = $buffer->fetch();
            } catch (Throwable $e) {
                report($e);
                $code = 1;
                $output = $buffer->fetch()."\n".$e->getMessage();
            } finally {
                $lock->release();
            }
            self::complete($run, $code, $output);
        });

        return null;
    }

    private static function complete(ScheduledTaskRun $run, int $exitCode, string $output): void
    {
        $output = trim(preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $output) ?? '');
        if (mb_strlen($output) > self::OUTPUT_MAX) {
            $output = '…'.mb_substr($output, -self::OUTPUT_MAX);
        }
        $output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');

        $run->fill([
            'status' => $exitCode === 0 ? 'success' : 'failed',
            'exit_code' => $exitCode,
            'finished_at' => now(),
            'duration_ms' => (int) min(4_000_000_000, max(0, $run->started_at->diffInMilliseconds(now()))),
            'output' => $output !== '' ? $output : null,
        ])->save();

        if ($exitCode !== 0 && $run->trigger === 'schedule') {
            self::alert($run);
        }
    }

    /** A failed scheduled run is posted to the admin alerts chat (at most once per ALERT_HOURS per task). */
    private static function alert(ScheduledTaskRun $run): void
    {
        $chatId = app(AiErrorAlerts::class)->chatId();
        $telegram = app(TelegramApi::class);
        if (! $chatId || ! $telegram->configured() || ! Cache::add("scheduled-task-alert:{$run->task}", true, now()->addHours(self::ALERT_HOURS))) {
            return;
        }

        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $task = self::get($run->task);
        $text = "⚠️ <b>Scheduled task failed</b>\n\n<b>Task:</b> ".$e($task['label'] ?? $run->task)
            ."\n<b>Command:</b> <code>".$e(self::commandLine($run->task)).'</code>'
            ."\n<b>Exit code:</b> {$run->exit_code}"
            .($run->output ? "\n<b>Output:</b> <code>".$e(mb_strimwidth(AiErrorAlerts::withoutSecrets($run->output), 0, 600, '…')).'</code>' : '')
            ."\n<b>Time:</b> ".now()->format('d.m.Y H:i')
            ."\n\nAdmin → Scheduled tasks. <i>Repeats are muted for ".self::ALERT_HOURS.' hours.</i>';

        try {
            $sent = $telegram->sendMessage($chatId, $text, ['parse_mode' => 'HTML', 'disable_web_page_preview' => true], timeout: 5);
        } catch (Throwable) {
            $sent = false;
        }
        if (! $sent) {
            Cache::forget("scheduled-task-alert:{$run->task}");
        }
    }

    /** Artisan::call takes ['action' => 'info']; the scheduler's command line takes arguments as bare values. */
    private static function cliParameters(array $parameters): array
    {
        $out = [];
        foreach ($parameters as $name => $value) {
            str_starts_with((string) $name, '--') ? $out[$name] = $value : $out[] = $value;
        }

        return $out;
    }

    /** What to type on the server to run the task by hand. */
    public static function commandLine(string $key): string
    {
        $task = self::get($key);
        if (! $task) {
            return '';
        }

        $args = [];
        foreach ($task['parameters'] as $name => $value) {
            if (str_starts_with((string) $name, '--')) {
                foreach ((array) $value as $v) {
                    $args[] = $name.'="'.$v.'"';
                }
            } else {
                $args[] = $value;
            }
        }

        return trim('php artisan '.$task['command'].' '.implode(' ', $args));
    }

    public static function nextRun(string $key): ?Carbon
    {
        $cron = self::get($key)['cron'] ?? null;

        $tz = self::get($key)['timezone'] ?? config('app.timezone');

        return $cron ? Carbon::instance((new CronExpression($cron))->getNextRunDate(now($tz), 0, false, $tz))->setTimezone(config('app.timezone')) : null;
    }

    public static function latest(string $key): ?ScheduledTaskRun
    {
        return ScheduledTaskRun::where('task', $key)->latest('started_at')->latest('id')->first();
    }

    /** Is the cron scheduler alive? A scheduled run started in the last few minutes, or one still running. */
    public static function schedulerRunning(): bool
    {
        // (Not InvoiceParser's heartbeat: a "Run now" of the invoice task sets it too.)
        if (ScheduledTaskRun::where('trigger', 'schedule')->where('started_at', '>=', now()->subMinutes(3))->exists()) {
            return true;
        }

        // A long invoice run blocks the next minute's start (no overlap), so a run still going counts too.
        return ScheduledTaskRun::where('trigger', 'schedule')->where('status', 'running')->get()
            ->contains(fn (ScheduledTaskRun $r) => $r->state() === 'running');
    }

    /** How many tasks' latest run failed (the menu badge; one query). */
    public static function failedCount(): int
    {
        return count(self::failedTasks());
    }

    /** Labels of the tasks whose latest run failed or never finished (stuck): the dashboard warning. One query. */
    public static function failedTasks(): array
    {
        return ScheduledTaskRun::whereIn('id',
            ScheduledTaskRun::selectRaw('max(id)')->whereIn('task', array_keys(self::all()))->groupBy('task')
        )->whereIn('status', ['failed', 'running'])->get(['id', 'task', 'status', 'started_at'])
            ->filter(fn (ScheduledTaskRun $r) => in_array($r->state(), ['failed', 'stuck'], true))
            ->map(fn (ScheduledTaskRun $r) => self::get($r->task)['label'])->sort()->values()->all();
    }

    public static function lastSchedulerRun(): ?Carbon
    {
        $at = ScheduledTaskRun::where('trigger', 'schedule')->max('started_at');

        return $at ? Carbon::parse($at) : null;
    }
}
