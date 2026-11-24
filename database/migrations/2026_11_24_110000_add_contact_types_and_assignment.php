<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who a Telegram contact is (client | supplier | employee; null = not assigned yet). Until an admin assigns a
        // type the AI only greets once and presents the company; clients get the AI team, suppliers the greeting only,
        // employees nothing. ai_employee_id: a chosen AI agent (null = by role).
        Schema::table('telegram_users', function (Blueprint $table) {
            Schema::hasColumn('telegram_users', 'contact_type') || $table->string('contact_type', 10)->nullable()->index();
            Schema::hasColumn('telegram_users', 'employee_role') || $table->string('employee_role', 60)->nullable();
            Schema::hasColumn('telegram_users', 'ai_employee_id') || $table->foreignId('ai_employee_id')->nullable()->constrained()->nullOnDelete();
            Schema::hasColumn('telegram_users', 'assigned_at') || $table->dateTime('assigned_at')->nullable();
            Schema::hasColumn('telegram_users', 'greeted_at') || $table->dateTime('greeted_at')->nullable();
            Schema::hasColumn('telegram_users', 'assignment_requested_at') || $table->dateTime('assignment_requested_at')->nullable();
        });

        Schema::table('sales_assistant_settings', function (Blueprint $table) {
            // null = config services.telegram.sales_require_assignment (on; the test suite: off)
            Schema::hasColumn('sales_assistant_settings', 'require_assignment') || $table->boolean('require_assignment')->nullable();
            Schema::hasColumn('sales_assistant_settings', 'greeting_prompt') || $table->text('greeting_prompt')->nullable(); // null = resources/prompts/sales-greeting.md
            Schema::hasColumn('sales_assistant_settings', 'greeting_channels') || $table->json('greeting_channels')->nullable(); // null = bot + business
            Schema::hasColumn('sales_assistant_settings', 'employee_roles') || $table->text('employee_roles')->nullable(); // one per line; null = defaults
        });

        // Everyone already known keeps talking with the AI team: employees stay employees, the rest become clients.
        $now = now();
        DB::table('telegram_users')->whereNull('contact_type')->where('is_employee', true)->update(['contact_type' => 'employee', 'assigned_at' => $now]);
        DB::table('telegram_users')->whereNull('contact_type')->update(['contact_type' => 'client', 'assigned_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_users', 'ai_employee_id')) {
                $table->dropConstrainedForeignId('ai_employee_id');
            }
            foreach (['contact_type', 'employee_role', 'assigned_at', 'greeted_at', 'assignment_requested_at'] as $column) {
                if (Schema::hasColumn('telegram_users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('sales_assistant_settings', function (Blueprint $table) {
            foreach (['require_assignment', 'greeting_prompt', 'greeting_channels', 'employee_roles'] as $column) {
                if (Schema::hasColumn('sales_assistant_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
