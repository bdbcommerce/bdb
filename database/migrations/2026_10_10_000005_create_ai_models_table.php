<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI models the assistant may use (Admin → AI settings). For each request the active models
 * for that task (website chat, Telegram bot, admin tools) are tried by priority; a model that
 * fails is paused until cooldown_until and the next one answers. With no rows, the Gemini
 * settings from .env are used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 32);              // gemini | openai (any OpenAI-compatible API)
            $table->string('model');
            $table->string('base_url')->nullable();      // OpenAI-compatible endpoint, e.g. https://api.groq.com/openai/v1
            $table->text('api_key')->nullable();         // encrypted; empty Gemini key = GEMINI_API_KEY
            $table->json('tasks');
            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
