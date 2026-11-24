<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge gaps (AI team): the sales assistant tags a question it could not answer with the
 * sales-knowledge kind the answer belongs to; an admin's answer may be a sales-knowledge entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->string('knowledge_kind', 16)->nullable()->after('category');
            $table->foreignId('sales_knowledge_id')->nullable()->after('knowledge_entry_id')->constrained('sales_knowledge')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_knowledge_id');
            $table->dropColumn('knowledge_kind');
        });
    }
};
