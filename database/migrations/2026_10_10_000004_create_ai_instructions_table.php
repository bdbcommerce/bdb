<?php

use App\Services\ChatAssistant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Markdown documents admins write for the AI assistant: the single "prompt" (main instructions,
 * seeded below), "rules" (how to behave) and "info" (extra facts). Active ones are added to every AI request, for all channels or
 * only the website / Telegram bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_instructions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('kind', 16)->default('rules');
            $table->string('channel', 16)->default('all');
            $table->text('content');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // The AI's main prompt starts as the built-in text and is edited in the admin panel.
        DB::table('ai_instructions')->insert([
            'title' => 'Main prompt',
            'kind' => 'prompt',
            'channel' => 'all',
            'content' => ChatAssistant::DEFAULT_PROMPT,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_instructions');
    }
};
