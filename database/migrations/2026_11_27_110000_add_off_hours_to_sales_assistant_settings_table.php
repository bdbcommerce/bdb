<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Walk-through step 7: public holidays (off hours all day) and the morning summary of what came in overnight. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->text('holidays')->nullable()->after('work_end');
            $table->boolean('morning_summary')->default(true)->after('holidays');
            $table->date('morning_summary_sent_on')->nullable()->after('morning_summary');
        });
    }

    public function down(): void
    {
        Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->dropColumn(['holidays', 'morning_summary', 'morning_summary_sent_on']);
        });
    }
};
