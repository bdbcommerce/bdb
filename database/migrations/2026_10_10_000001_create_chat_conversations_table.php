<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every chat (website or Telegram bot), with or without a callback request, so admins can see
 * what visitors ask and where the assistant had no answer. Pruned after 90 days.
 * external_id holds the Telegram chat ID; messages may include Telegram media as {type, file_id}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('channel', 16)->default('web');
            $table->string('external_id')->nullable();
            $table->string('locale', 2);
            $table->string('page')->nullable();
            $table->json('messages');
            $table->unsignedSmallInteger('message_count')->default(0);
            $table->unsignedSmallInteger('unanswered_count')->default(0);
            $table->foreignId('chat_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('updated_at');
            $table->index(['channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
