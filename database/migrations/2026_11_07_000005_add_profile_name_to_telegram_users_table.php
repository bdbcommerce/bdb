<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name from the customer's Telegram profile (first + last name). Shown when the customer
 * has not typed a name in the bot's registration — e.g. while the bot only records chats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->string('profile_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn('profile_name');
        });
    }
};
