<?php

use App\Models\ChatConversation;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete chat conversations older than ChatConversation::KEEP_DAYS.
Schedule::command('model:prune', ['--model' => [ChatConversation::class]])->daily();
