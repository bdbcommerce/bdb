<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The photo now goes to the invoice chat together with the summary: the separate image chat is not needed. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoice_parser_settings', 'image_chat_id')) {
            Schema::table('invoice_parser_settings', function (Blueprint $table) {
                $table->dropColumn('image_chat_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoice_parser_settings', 'image_chat_id')) {
            Schema::table('invoice_parser_settings', function (Blueprint $table) {
                $table->string('image_chat_id', 64)->nullable()->after('json_chat_id');
            });
        }
    }
};
