<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers BDB serves in an industry but does not list by name (e.g. under NDA). The home page
 * shows active listed customers + this number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('industries', function (Blueprint $table) {
            $table->unsignedInteger('extra_customers')->default(0)->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('industries', function (Blueprint $table) {
            $table->dropColumn('extra_customers');
        });
    }
};
