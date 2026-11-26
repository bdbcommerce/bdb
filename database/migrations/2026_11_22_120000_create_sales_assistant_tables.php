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
            $table->string('bot_mode', 4)->default('on'); // on | off — the bot chat
            $table->string('business_mode', 4)->default('on'); // on | off — Premium account chats
            $table->unsignedSmallInteger('wait_minutes')->default(5); // Business, working hours: wait for a manager first
            $table->json('work_days')->nullable(); // ISO days 1 = Monday; null = Mon–Fri
            $table->string('work_start', 5)->default('09:00');
            $table->string('work_end', 5)->default('18:00');
            // Outside working hours: note = the working hours once, then silence until the office opens; ai = answer as
            // in working hours; silent = nothing. null = config services.telegram.sales_off_hours (default note).
            $table->string('off_hours_mode', 10)->nullable();
            $table->unsignedSmallInteger('remind_after_hours')->default(2);
            $table->unsignedSmallInteger('close_after_hours')->default(24);
            $table->unsignedSmallInteger('follow_up_days')->default(14);
            $table->unsignedBigInteger('large_order_sum')->nullable(); // hand over at once from this total (sum); null = LARGE_ORDER_SUM, 0 = off
            $table->string('model', 64)->default('claude-haiku-5-5');
            $table->mediumText('prompt')->nullable(); // null = resources/prompts/sales-assistant.md
            $table->text('platform_rules')->nullable(); // first prompt layer of every AI employee; null = AiEmployeeTemplates::PLATFORM_RULES
            $table->text('employee_ids')->nullable(); // extra Telegram IDs / @usernames of employees, one per line
            $table->text('employee_roles')->nullable(); // one per line; null = defaults
            $table->boolean('require_assignment')->nullable(); // null = config services.telegram.sales_require_assignment
            $table->text('greeting_prompt')->nullable(); // null = resources/prompts/sales-greeting.md
            $table->json('greeting_channels')->nullable(); // null = bot + business
            $table->string('new_contact_mode', 10)->nullable(); // SalesAssistantSetting::NEW_CONTACT_MODES; null = smart
            $table->unsignedSmallInteger('expert_remind_minutes')->nullable(); // null = SalesAssistantSetting::EXPERT_REMIND_MINUTES
            $table->unsignedSmallInteger('expert_escalate_minutes')->nullable(); // null = SalesAssistantSetting::EXPERT_ESCALATE_MINUTES
            $table->json('claude_data')->nullable(); // what a turn sends to Claude, SalesAssistantSetting::CLAUDE_DATA; null = everything
            $table->boolean('dev_mode')->default(false); // record the trace of every real customer message (AiTrace::KEEP_DAYS)
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

        // A client request answered by sales knowledge (Knowledge gaps).
        Schema::table('client_requests', function (Blueprint $table) {
            $table->foreignId('sales_knowledge_id')->nullable()->constrained('sales_knowledge')->nullOnDelete();
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
        Schema::table('client_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_knowledge_id');
        });
        Schema::dropIfExists('sales_knowledge');
        Schema::dropIfExists('sales_assistant_settings');
    }
};
