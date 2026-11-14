<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer's phone was confirmed: shared with Telegram's contact button in the bot chat, or
 * entered by an admin. A phone typed in a Premium-account (Business) chat is not verified, and the bot
 * asks for the contact button before a call request becomes a lead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });

        // Customers who registered in the bot chat shared their contact (the only way to register there).
        DB::table('telegram_users')->where('source', 'bot')->whereNotNull('registered_at')->whereNotNull('phone')
            ->update(['phone_verified_at' => DB::raw('registered_at')]);
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
