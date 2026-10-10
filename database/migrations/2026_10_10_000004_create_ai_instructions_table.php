<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markdown documents admins write for the AI assistant: "rules" (how to behave) and
 * "info" (extra facts). Active ones are added to every AI request, for all channels or
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
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_instructions');
    }
};
