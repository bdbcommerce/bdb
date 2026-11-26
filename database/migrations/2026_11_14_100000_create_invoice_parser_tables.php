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
            // Only photos from these senders (Telegram user / sender-chat IDs, comma separated) are read; empty = anyone.
            $table->string('source_from_ids', 1000)->nullable();
            // Seller STIRs (comma separated): only their invoices go to the invoice chat, others to the JSON recipient. Empty = all.
            $table->string('seller_inns', 1000)->nullable();
            $table->string('target_chat_id', 64)->nullable(); // and posts the summary here
            $table->string('json_chat_id', 64)->nullable(); // the cleaned JSON of each accepted invoice (optional)
            $table->text('prompt')->nullable();           // null = the built-in prompt
            $table->boolean('notify_skipped')->default(false);
            $table->unsignedSmallInteger('daily_limit')->nullable(); // paid Claude reads per day; empty = no limit
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
            $table->string('sender_id', 64)->nullable();
            $table->string('caption', 1024)->nullable();
            // pending → processing → sent (accepted and posted) | skipped (not an invoice) | failed
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            // Every paid read of this photo added up ("Read again" and retries included), in US dollars.
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedSmallInteger('read_count')->default(0);
            $table->unsignedBigInteger('sent_message_id')->nullable();
            $table->unsignedBigInteger('json_message_id')->nullable();
            $table->unsignedBigInteger('image_message_id')->nullable();
            $table->timestamp('read_at')->nullable()->index(); // when Claude last read it (daily limit)
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
        });

        // One row per paid Claude read: spend per day / month and the daily limit count reads when they happened.
        Schema::create('invoice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reads');
        Schema::dropIfExists('invoice_messages');
        Schema::dropIfExists('invoice_parser_settings');
    }
};
