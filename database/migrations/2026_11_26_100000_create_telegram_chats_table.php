<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every Telegram chat the bot has seen (groups it is in, private chats with it) — for the chat / group pickers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 32)->unique();
            $table->string('type', 16)->index(); // private | group | supergroup | channel
            $table->string('title')->nullable(); // group title, or the person's first + last name
            $table->string('username')->nullable();
            $table->string('bot_status', 16)->nullable(); // member | administrator | left | kicked (from my_chat_member)
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_chats');
    }
};
