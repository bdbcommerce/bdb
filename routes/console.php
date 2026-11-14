<?php

use App\Models\ChatConversation;
use App\Models\JobApplication;
use App\Models\LinkClick;
use App\Models\PageView;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete chat conversations older than ChatConversation::KEEP_DAYS.
Schedule::command('model:prune', ['--model' => [ChatConversation::class, JobApplication::class, PageView::class, LinkClick::class]])->daily();

// Retry CVs whose Telegram upload failed (they wait on the private disk until sent).
Schedule::command('careers:send-cvs')->hourly()->withoutOverlapping();

// Invoice parser: read photos still waiting, stuck or failed (each is normally read right after it arrives).
Schedule::command('invoices:process')->everyMinute()->withoutOverlapping(10);
