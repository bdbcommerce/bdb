<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            // Link to the online shop already filtered by this brand (e.g. a mylabel.uz catalog filter).
            $table->string('shop_url', 500)->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('shop_url');
        });
    }
};
