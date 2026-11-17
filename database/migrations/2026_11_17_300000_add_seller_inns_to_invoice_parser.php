<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            // Seller STIRs (comma separated): only their invoices go to the invoice chat, others to the JSON recipient. Empty = all.
            $table->string('seller_inns', 1000)->nullable()->after('source_from_ids');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            $table->dropColumn('seller_inns');
        });
    }
};
