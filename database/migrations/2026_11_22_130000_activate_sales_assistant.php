<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The sales assistant's state per conversation: stage, collected details, lead, unanswered questions.
        Schema::hasColumn('chat_conversations', 'sales_state') || Schema::table('chat_conversations', function (Blueprint $table) {
            $table->json('sales_state')->nullable();
        });
        // Switched on for everyone (the owner's decision: no test phase); new rows default to "on" in the model.
        DB::table('sales_assistant_settings')->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        // It starts with new messages: what customers wrote before the deploy is left to the managers.
        DB::table('chat_conversations')->whereIn('channel', ['telegram', 'business'])->where('updated_at', '>=', now()->subDays(2))
            ->orderBy('id')->select(['id', 'message_count'])->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('chat_conversations')->where('id', $row->id)->update(['sales_state' => json_encode(['handled' => (int) $row->message_count])]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', fn (Blueprint $table) => $table->dropColumn('sales_state'));
    }
};
