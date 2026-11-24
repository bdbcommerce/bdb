<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outside working hours: note = the working hours once, then silence until the office opens (default);
        // ai = the assistant answers as in working hours (the earlier behaviour); silent = nothing is sent.
        Schema::hasColumn('sales_assistant_settings', 'off_hours_mode') || Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->string('off_hours_mode', 10)->nullable(); // null = config services.telegram.sales_off_hours (default note)
        });
    }

    public function down(): void
    {
        Schema::hasColumn('sales_assistant_settings', 'off_hours_mode') && Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->dropColumn('off_hours_mode');
        });
    }
};
