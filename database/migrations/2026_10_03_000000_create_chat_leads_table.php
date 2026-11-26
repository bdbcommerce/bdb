<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_leads', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16)->default('web');
            $table->string('name');
            $table->string('phone');
            $table->text('topic')->nullable();
            $table->string('locale', 2);
            $table->string('page_url')->nullable();
            $table->json('transcript')->nullable();
            $table->boolean('is_handled')->default(false);
            // The sales assistant's handover note: how hot the customer is, why it was handed over, what next.
            $table->string('temperature', 5)->nullable(); // hot | warm | cold
            $table->string('competitor', 200)->nullable(); // a competitor or their price the customer named
            $table->string('handover_reason', 300)->nullable();
            $table->string('next_step', 300)->nullable();
            $table->json('quoted')->nullable(); // [{product, price}] the prices the AI gave, with the catalog date
            // "I take it" in the sales chat; the main admin is reminded when nobody took it in time.
            $table->string('taken_by', 100)->nullable();
            $table->string('taken_by_telegram_id', 32)->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_leads');
    }
};
