<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shadow mode: an AI employee's reply waits for a manager (send as is / edit and send / discard); the
        // decisions are kept to train the employee and to know when it can go live.
        Schema::hasTable('ai_shadow_drafts') || Schema::create('ai_shadow_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->text('draft');
            $table->text('final')->nullable();
            $table->string('status', 10)->default('pending'); // pending | sent | edited | discarded | stale
            $table->string('decided_by', 100)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'ai_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_shadow_drafts');
    }
};
