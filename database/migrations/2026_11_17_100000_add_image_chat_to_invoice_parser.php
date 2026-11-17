<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            // The original photo of each accepted invoice (turned upright) is posted here (optional; may be the invoice chat).
            $table->string('image_chat_id', 64)->nullable()->after('json_chat_id');
        });

        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('image_message_id')->nullable()->after('json_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->dropColumn('image_message_id');
        });
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            $table->dropColumn('image_chat_id');
        });
    }
};
