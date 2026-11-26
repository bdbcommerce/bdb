<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram bot customers. Before talking to the assistant each one picks a language
 * and registers a name and the phone number linked to their Telegram account.
 * "step" is the registration step the bot is waiting on: language | name | phone | done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->unique();
            $table->string('username')->nullable();
            $table->string('locale', 2)->nullable();
            $table->string('step', 16)->default('language');
            $table->string('name')->nullable();
            $table->string('profile_name')->nullable(); // Telegram first + last name, refreshed on every message
            $table->string('phone')->nullable();
            // Shared with the contact button in the bot chat, or entered by an admin.
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('company', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('ask_registration')->default(false);
            $table->string('source', 16)->default('bot'); // bot | business
            $table->timestamp('registered_at')->nullable();
            // Who the contact is (client | supplier | employee; null = not assigned yet). Until an admin assigns a
            // type the AI only greets once and presents the company; clients get the AI team, suppliers the greeting
            // only, employees nothing. The assigned AI agent (ai_employee_id) is added with the ai_employees table.
            $table->boolean('is_employee')->default(false); // the assistant never talks to them
            $table->string('contact_type', 10)->nullable()->index();
            $table->string('employee_role', 60)->nullable();
            $table->boolean('answers_questions')->default(false); // an expert of their employee_role
            $table->boolean('is_main_admin')->default(false); // gets the escalated expert questions (one person)
            $table->timestamp('bot_chat_at')->nullable(); // last private message to the bot (they pressed /start)
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('greeted_at')->nullable();
            $table->dateTime('assignment_requested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
