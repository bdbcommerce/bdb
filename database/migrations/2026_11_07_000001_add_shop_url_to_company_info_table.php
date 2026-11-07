<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_info', function (Blueprint $table) {
            // Online shop (catalogue) linked from the site; empty hides the shop block and links.
            $table->string('shop_url', 255)->nullable()->default('https://mylabel.uz');
        });
    }

    public function down(): void
    {
        Schema::table('company_info', function (Blueprint $table) {
            $table->dropColumn('shop_url');
        });
    }
};
