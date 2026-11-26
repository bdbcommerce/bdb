<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The path of one AI turn, step by step (customer → Laravel → Claude API → Laravel → Telegram), with what was sent.
        Schema::create('ai_traces', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10)->default('live'); // live | sandbox
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sandbox_chat_id')->nullable()->index();
            $table->foreignId('ai_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('kind', 20)->default('reply'); // reply | reminder | follow-up
            $table->string('status', 12); // sent | silent | dropped | failed | limit
            $table->string('customer_text', 300)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 5)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('steps');
            $table->timestamps();
            $table->index(['source', 'created_at']);
        });
        Schema::create('ai_sandbox_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prompt', 10)->default('published'); // published | draft
            $table->string('via', 10)->default('bot');
            $table->json('messages')->nullable();
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_sandbox_chats');
    }
};
