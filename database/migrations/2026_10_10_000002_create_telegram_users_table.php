<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram bot customers. Before talking to the assistant each one picks a language
 * and registers a name and the phone number linked to their Telegram account.
 * "step" is the registration step the bot is waiting on: language | name | phone | done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->unique();
            $table->string('username')->nullable();
            $table->string('locale', 2)->nullable();
            $table->string('step', 16)->default('language');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
