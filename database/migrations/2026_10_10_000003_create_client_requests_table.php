<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every real customer question (website chat and Telegram bot), tagged by the AI with a
 * short topic and category, and linked to the knowledge entry that answers it.
 * status: answered (AI answered from site data) | open (no answer yet) |
 *         resolved (an admin's knowledge entry answers it) | dismissed.
 * Kept after the conversation itself is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16)->default('web');
            $table->string('external_id')->nullable();
            $table->string('locale', 2);
            $table->text('question');
            $table->text('ai_reply')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('topic', 120)->nullable();
            $table->string('category', 32)->nullable();
            $table->foreignId('knowledge_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'topic']);
            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
    }
};
