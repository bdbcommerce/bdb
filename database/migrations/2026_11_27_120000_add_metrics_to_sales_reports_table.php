<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Walk-through step 8: the measured numbers of a report (App\Services\SalesReport\SalesMetrics). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_reports', function (Blueprint $table) {
            $table->json('metrics')->nullable()->after('report');
        });
    }

    public function down(): void
    {
        Schema::table('sales_reports', function (Blueprint $table) {
            $table->dropColumn('metrics');
        });
    }
};
