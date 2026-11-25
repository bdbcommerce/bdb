<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expert questions: what the AI could not answer (or was not sure of) goes to an employee who answers AI questions,
 * one question at a time, in the bot chat. The answers are the training record and become knowledge drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->boolean('answers_questions')->default(false)->after('employee_role');
            $table->boolean('is_main_admin')->default(false)->after('answers_questions');
            $table->timestamp('bot_chat_at')->nullable()->after('is_main_admin');
        });
        Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('expert_remind_minutes')->nullable();
            $table->unsignedSmallInteger('expert_escalate_minutes')->nullable();
        });
        Schema::create('expert_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('expert_questions')->nullOnDelete();
            $table->foreignId('client_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->text('customer_text')->nullable();
            $table->text('ai_draft')->nullable();
            $table->string('topic', 120)->nullable();
            $table->string('knowledge_kind', 16)->nullable();
            $table->string('department', 60)->nullable();
            $table->boolean('to_admin')->default(false);
            $table->string('language', 2)->default('ru');
            $table->string('status', 16)->default('queued')->index();
            $table->foreignId('expert_id')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('fallback_at')->nullable();
            $table->unsignedTinyInteger('transfers')->default(0);
            $table->text('note')->nullable();
            $table->json('pending')->nullable();
            $table->json('declined_by')->nullable();
            $table->text('answer')->nullable();
            $table->string('verdict', 16)->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('telegram_users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->string('delivered', 16)->nullable();
            $table->foreignId('sales_knowledge_id')->nullable()->constrained('sales_knowledge')->nullOnDelete();
            $table->json('history')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_questions');
        Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->dropColumn(['expert_remind_minutes', 'expert_escalate_minutes']);
        });
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn(['answers_questions', 'is_main_admin', 'bot_chat_at']);
        });
    }
};
