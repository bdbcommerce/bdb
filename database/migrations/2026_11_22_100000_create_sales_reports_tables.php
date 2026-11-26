<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin → Reports → Sales report (singleton).
        Schema::create('sales_report_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true); // weekly, to the invoice parser's source group
            $table->boolean('daily_enabled')->default(true); // daily 18:00 Tashkent, to the invoice parser's JSON recipient
            $table->string('language', 2)->default('uz');
            $table->json('channels')->nullable(); // ChatConversation channels to analyse; null = telegram + business
            $table->text('instructions')->nullable(); // extra notes for Claude
            $table->timestamps();
        });

        // One report: weekly (the source group) or daily (the JSON recipient).
        Schema::create('sales_reports', function (Blueprint $table) {
            $table->id();
            $table->string('period', 4)->default('week'); // week | day
            $table->dateTime('period_start'); // dateTime: MySQL refuses a second NOT NULL timestamp without a default
            $table->dateTime('period_end');
            $table->string('trigger', 10); // schedule | manual
            $table->string('status', 10); // sent | failed | empty
            $table->string('chat_id', 64)->nullable();
            $table->unsignedInteger('conversations')->default(0);
            $table->json('report')->nullable(); // Claude's JSON
            $table->longText('html')->nullable(); // the Telegram message(s), joined
            $table->json('message_ids')->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_reports');
        Schema::dropIfExists('sales_report_settings');
    }
};
