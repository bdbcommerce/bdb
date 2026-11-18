<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScheduledTaskRun;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin → Scheduled tasks: every background task, its last runs, and "Run now". */
class ScheduledTaskController extends Controller
{
    public const RECENT = 10;

    public function index(): View
    {
        $tasks = [];
        foreach (ScheduledTasks::all() as $key => $task) {
            $recent = ScheduledTaskRun::with('user')->where('task', $key)
                ->latest('started_at')->latest('id')->limit(self::RECENT)->get();
            $tasks[$key] = $task + [
                'key' => $key,
                'command_line' => ScheduledTasks::commandLine($key),
                'next' => ScheduledTasks::nextRun($key),
                'latest' => $recent->first(),
                'recent' => $recent,
                'last_success' => ScheduledTaskRun::where('task', $key)->where('status', 'success')->max('finished_at'),
            ];
        }

        return view('admin.scheduled-tasks.index', [
            'tasks' => $tasks,
            'schedulerRunning' => ScheduledTasks::schedulerRunning(),
            'lastSchedulerRun' => ScheduledTasks::lastSchedulerRun(),
            'basePath' => base_path(),
        ]);
    }

    public function run(Request $request, string $task): RedirectResponse
    {
        abort_unless(ScheduledTasks::get($task), 404);

        $error = ScheduledTasks::runNow($task, $request->user()?->id);

        $back = redirect()->route('admin.scheduled-tasks.index');

        return $error
            ? $back->withErrors(['task' => $error])
            : $back->with('success', '"'.ScheduledTasks::get($task)['label'].'" started. Reload the page in a moment to see the result.');
    }
}
