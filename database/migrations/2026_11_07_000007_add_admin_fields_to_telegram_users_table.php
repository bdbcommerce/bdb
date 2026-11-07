<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers are created from their Telegram chat info (no registration needed). Admins can add
 * company and notes, and tick ask_registration so the bot asks for language, name and phone the
 * next time the customer writes to it. source: where we first saw them (bot | business).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->string('company', 120)->nullable()->after('phone');
            $table->text('notes')->nullable()->after('company');
            $table->boolean('ask_registration')->default(false)->after('notes');
            $table->string('source', 16)->default('bot')->after('ask_registration');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn(['company', 'notes', 'ask_registration', 'source']);
        });
    }
};
