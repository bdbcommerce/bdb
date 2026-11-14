<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin → Invoice parser → Settings (singleton, id = 1).
        Schema::create('invoice_parser_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();          // Claude API key, encrypted
            $table->string('model', 64)->nullable();
            $table->string('source_chat_id', 64)->nullable(); // the bot reads invoice photos here
            $table->string('target_chat_id', 64)->nullable(); // and posts the summary here
            $table->text('prompt')->nullable();           // null = the built-in prompt
            $table->boolean('notify_skipped')->default(false);
            $table->unsignedSmallInteger('daily_limit')->default(100); // paid Claude reads per day
            $table->timestamps();
        });

        // One row per photo / file received in the source chat.
        Schema::create('invoice_messages', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64);
            $table->unsignedBigInteger('message_id');
            $table->string('file_id');
            $table->string('mime', 64)->nullable();
            $table->string('sender')->nullable();
            $table->string('caption', 1024)->nullable();
            // pending → processing → sent (accepted and posted) | skipped (not an invoice) | failed
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('sent_message_id')->nullable();
            $table->timestamp('read_at')->nullable()->index(); // when Claude last read it (daily limit)
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_messages');
        Schema::dropIfExists('invoice_parser_settings');
    }
};
