<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            // Only photos from these senders (Telegram user / sender-chat IDs, comma separated) are read; empty = anyone.
            $table->string('source_from_ids', 1000)->nullable()->after('source_chat_id');
            // The cleaned JSON of each accepted invoice is posted here (optional).
            $table->string('json_chat_id', 64)->nullable()->after('target_chat_id');
        });

        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->string('sender_id', 64)->nullable()->after('sender');
            $table->unsignedBigInteger('json_message_id')->nullable()->after('sent_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_messages', function (Blueprint $table) {
            $table->dropColumn(['sender_id', 'json_message_id']);
        });
        Schema::table('invoice_parser_settings', function (Blueprint $table) {
            $table->dropColumn(['source_from_ids', 'json_chat_id']);
        });
    }
};
