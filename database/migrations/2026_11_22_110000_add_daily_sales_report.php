<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The daily report (18:00 Tashkent) goes to the invoice parser's JSON recipient.
        Schema::hasColumn('sales_report_settings', 'daily_enabled') || Schema::table('sales_report_settings', function (Blueprint $table) {
            $table->boolean('daily_enabled')->default(true)->after('enabled');
        });
        Schema::hasColumn('sales_reports', 'period') || Schema::table('sales_reports', function (Blueprint $table) {
            $table->string('period', 4)->default('week')->after('id'); // week | day
        });
    }

    public function down(): void
    {
        Schema::table('sales_reports', fn (Blueprint $table) => $table->dropColumn('period'));
        Schema::table('sales_report_settings', fn (Blueprint $table) => $table->dropColumn('daily_enabled'));
    }
};
