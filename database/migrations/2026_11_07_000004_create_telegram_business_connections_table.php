<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recording beyond the bot's own private chats:
 * - Telegram Business: a Premium account connects the bot (Settings → Telegram Business → Chatbots)
 *   and the bot receives that account's private chats. One row per connection.
 * Group chats and Premium-account chats are stored as chat_conversations (title, business_connection_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_business_connections', function (Blueprint $table) {
            $table->id();
            $table->string('connection_id')->unique();
            $table->string('user_id');              // the Premium account owner
            $table->string('user_chat_id')->nullable();
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('can_reply')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_business_connections');
    }
};
