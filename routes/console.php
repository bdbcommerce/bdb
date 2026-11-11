<?php

use App\Models\ChatConversation;
use App\Models\JobApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete chat conversations older than ChatConversation::KEEP_DAYS.
Schedule::command('model:prune', ['--model' => [ChatConversation::class, JobApplication::class]])->daily();

// Retry CVs whose Telegram upload failed (they wait on the private disk until sent).
Schedule::command('careers:send-cvs')->hourly()->withoutOverlapping();
