<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram groups page: a group's messages can be left unrecorded. Groups known only from recorded
 * conversations (before the chats list existed) are added to the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->boolean('is_recorded')->default(true)->after('bot_status');
        });

        $known = DB::table('telegram_chats')->pluck('chat_id')->all();
        $groups = DB::table('chat_conversations')->where('channel', 'group')->whereNotNull('external_id')
            ->orderByDesc('id')->get(['external_id', 'title', 'updated_at'])->unique('external_id');
        foreach ($groups as $g) {
            $id = (string) $g->external_id;
            if (in_array($id, $known, true) || ! preg_match('/^-\d{1,20}$/', $id)) {
                continue;
            }
            DB::table('telegram_chats')->insert(['chat_id' => $id, 'type' => 'supergroup', 'title' => $g->title ? mb_substr($g->title, 0, 255) : null,
                'is_recorded' => true, 'last_seen_at' => $g->updated_at, 'created_at' => now(), 'updated_at' => now()]);
            $known[] = $id;
        }
    }

    public function down(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            $table->dropColumn('is_recorded');
        });
    }
};
