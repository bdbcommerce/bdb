<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations → Products: what the AI team needs to know about a 1C item and 1C does not hold.
 * Filled by staff or drafted by the AI ("Fill with AI"); a 1C load never touches these columns.
 */
return new class extends Migration
{
    private const COLUMNS = ['brand', 'model', 'ai_category', 'ai_summary', 'specs', 'compatible_with', 'keywords', 'sales_note', 'ai_use', 'info_status', 'info_by', 'info_at'];

    public function up(): void
    {
        Schema::table('onec_items', function (Blueprint $table) {
            $table->string('brand', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('ai_category', 30)->nullable()->index(); // OnecItem::categories()
            $table->text('ai_summary')->nullable(); // what it is and who needs it
            $table->text('specs')->nullable(); // one "name: value" per line
            $table->text('compatible_with')->nullable(); // devices / consumables it works with
            $table->text('keywords')->nullable(); // the words customers use (uz / ru / en)
            $table->text('sales_note')->nullable(); // staff only: what to offer with it, what to warn about
            $table->boolean('ai_use')->default(true); // false = the AI must not offer it
            $table->string('info_status', 10)->nullable()->index(); // null = empty, ai = AI draft to check, checked = staff confirmed
            $table->string('info_by', 120)->nullable();
            $table->timestamp('info_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('onec_items', function (Blueprint $table) {
            $table->dropIndex(['ai_category']);
            $table->dropIndex(['info_status']);
        });
        Schema::table('onec_items', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
