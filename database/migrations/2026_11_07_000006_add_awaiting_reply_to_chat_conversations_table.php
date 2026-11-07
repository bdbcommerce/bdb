<?php

use App\Models\ChatConversation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Waiting for reply": Telegram chats (bot / Premium account) whose last message is the customer's.
 * Kept up to date by ChatConversation on save; handled_count lets staff mark a chat as answered
 * elsewhere (e.g. by phone) until the customer writes again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->boolean('awaiting_reply')->default(false)->after('unanswered_count');
            $table->timestamp('last_message_at')->nullable()->after('awaiting_reply');
            $table->unsignedInteger('handled_count')->default(0)->after('last_message_at');
            $table->index(['awaiting_reply', 'last_message_at']);
        });

        // Fill in the existing chats.
        ChatConversation::whereIn('channel', ChatConversation::REPLY_CHANNELS)->each(function (ChatConversation $conversation) {
            // Direct update: keeps updated_at (the 24 h session window) as it is.
            DB::table('chat_conversations')->where('id', $conversation->id)->update($conversation->replyState());
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex(['awaiting_reply', 'last_message_at']);
            $table->dropColumn(['awaiting_reply', 'last_message_at', 'handled_count']);
        });
    }
};
