<?php

namespace App\Console\Commands;

use App\Services\SalesAssistant\TestRunner;
use Illuminate\Console\Command;

/** Runs the AI employees' queued test cases (AI team → Test cases). Scheduled every minute. */
class RunAiTests extends Command
{
    protected $signature = 'ai:run-tests {--seconds=240 : Stop starting new runs after this many seconds}';

    protected $description = 'Run queued AI employee test cases (real Claude calls; Claude Haiku grades)';

    public function handle(TestRunner $runner): int
    {
        @set_time_limit(max(60, (int) $this->option('seconds') + 180));
        $done = $runner->processQueued((int) $this->option('seconds'));
        $this->info("Ran {$done} test case(s).");

        return self::SUCCESS;
    }
}
