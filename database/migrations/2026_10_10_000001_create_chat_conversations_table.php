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
            $table->string('business_connection_id')->nullable();
            $table->string('locale', 2);
            $table->string('page')->nullable();
            $table->string('title')->nullable(); // group chat title
            $table->json('messages');
            $table->unsignedSmallInteger('message_count')->default(0);
            $table->unsignedSmallInteger('unanswered_count')->default(0);
            // Waiting for reply: the customer wrote last (ChatConversation::replyState); Mark answered = handled_count.
            $table->boolean('awaiting_reply')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('handled_count')->default(0);
            // The sales assistant's state: stage, collected details, lead, unanswered questions.
            $table->json('sales_state')->nullable();
            $table->foreignId('chat_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('updated_at');
            $table->index(['channel', 'external_id']);
            $table->index(['awaiting_reply', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
