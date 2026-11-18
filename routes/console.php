<?php

use App\Http\Middleware\CacheHomePage;
use App\Services\Scheduling\ScheduledTasks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('site:clear-home-cache', function () {
    CacheHomePage::flush();
    $this->info('Home page cache cleared — it is rebuilt on the next visit.');
})->purpose('Clear the cached home page (run on deploy)');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every scheduled task (invoice photos, CVs, bot and AI checks, sitemap, home cache, pruning) is listed in
// ScheduledTasks::all() and shown in Admin → Scheduled tasks. Needs the cron line: * * * * * php artisan schedule:run
ScheduledTasks::register(app(Schedule::class));
