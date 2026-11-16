<?php

use App\Http\Middleware\CacheHomePage;
use App\Models\ChatConversation;
use App\Models\JobApplication;
use App\Models\LinkClick;
use App\Models\PageView;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('site:clear-home-cache', function () {
    CacheHomePage::flush();
    $this->info('Home page cache cleared — it is rebuilt on the next visit.');
})->purpose('Clear the cached home page (run on deploy)');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete chat conversations older than ChatConversation::KEEP_DAYS.
Schedule::command('model:prune', ['--model' => [ChatConversation::class, JobApplication::class, PageView::class, LinkClick::class]])->daily();

// Retry CVs whose Telegram upload failed (they wait on the private disk until sent).
Schedule::command('careers:send-cvs')->hourly()->withoutOverlapping();

// Invoice parser: the webhook only stores photos; this reads them one by one (waiting, stuck or failed).
// In the background: a run can wait minutes on Claude, and must not hold up the other scheduled jobs.
Schedule::command('invoices:process')->everyMinute()->withoutOverlapping(10)->runInBackground();
