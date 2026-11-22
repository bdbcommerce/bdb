<?php

use App\Models\SalesKnowledge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin → Sales assistant → Settings (singleton).
        Schema::create('sales_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->string('bot_mode', 4)->default('off'); // off | test | on — the bot chat
            $table->string('business_mode', 4)->default('off'); // off | test | on — Premium account chats
            $table->unsignedSmallInteger('wait_minutes')->default(5); // Business, working hours: wait for a manager first
            $table->json('work_days')->nullable(); // ISO days 1 = Monday; null = Mon–Fri
            $table->string('work_start', 5)->default('09:00');
            $table->string('work_end', 5)->default('18:00');
            $table->unsignedSmallInteger('remind_after_hours')->default(2);
            $table->unsignedSmallInteger('close_after_hours')->default(24);
            $table->unsignedSmallInteger('follow_up_days')->default(14);
            $table->string('model', 64)->default('claude-haiku-5-5');
            $table->mediumText('prompt')->nullable(); // null = resources/prompts/sales-assistant.md
            $table->text('employee_ids')->nullable(); // extra Telegram IDs / @usernames of employees, one per line
            $table->timestamps();
        });

        // Admin → Sales assistant → Knowledge: what the assistant may use (and nothing else).
        Schema::create('sales_knowledge', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // SalesKnowledge::KINDS
            $table->string('title');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['kind', 'sort']);
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->boolean('is_employee')->default(false); // the assistant never talks to them
            $table->boolean('is_tester')->default(false); // gets the assistant while a mode is "test"
        });

        $now = now();
        $rows = [];
        foreach (SalesKnowledge::DEFAULTS as $kind => $items) {
            foreach (array_values($items) as $i => [$title, $body]) {
                $rows[] = ['kind' => $kind, 'title' => $title, 'body' => $body, 'is_active' => true, 'sort' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('sales_knowledge')->insert($rows);
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn(['is_employee', 'is_tester']);
        });
        Schema::dropIfExists('sales_knowledge');
        Schema::dropIfExists('sales_assistant_settings');
    }
};
