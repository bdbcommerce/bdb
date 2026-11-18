<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per run of a task from App\Services\Scheduling\ScheduledTasks (Admin → Scheduled tasks).
        Schema::create('scheduled_task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 40);
            $table->string('trigger', 10); // schedule | manual
            $table->string('status', 10); // running | success | failed
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('output')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['task', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_task_runs');
    }
};
