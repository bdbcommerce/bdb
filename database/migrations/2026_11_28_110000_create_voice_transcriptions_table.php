<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every voice message sent for transcription (NeuronAI): the Telegram file ID and the text that came back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->string('file_id', 255)->index(); // Telegram file_id: downloads the audio again (bot token needed)
            $table->string('file_unique_id', 64)->nullable()->index(); // the same file in any chat
            $table->string('telegram_id', 32)->nullable()->index(); // who sent it
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('source', 20)->default('bot'); // bot (customer) | expert (an expert's voice answer)
            $table->string('provider', 20); // neuronai | groq
            $table->string('status', 10)->index(); // ok | empty (no speech) | failed
            $table->text('text')->nullable();
            $table->string('language', 10)->nullable(); // as detected by the provider
            $table->decimal('seconds', 8, 2)->nullable();
            $table->decimal('cost', 10, 2)->nullable(); // NeuronAI credits
            $table->string('request_id', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_transcriptions');
    }
};
