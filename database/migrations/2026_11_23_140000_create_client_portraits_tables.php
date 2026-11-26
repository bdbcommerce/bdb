<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client portraits: a company and the people we talk to there (the AI team reads them before answering).
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('inn', 20)->nullable()->index();
            $table->string('industry', 100)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('size', 150)->nullable();
            $table->string('status', 12)->default('prospect');
            $table->string('since', 20)->nullable();
            $table->string('owner', 100)->nullable();
            $table->text('installed')->nullable();
            $table->text('consumables')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('client_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telegram_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('previous_id')->nullable()->constrained('client_people')->nullOnDelete(); // the card at the earlier company
            $table->string('name', 100);
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('part', 20)->nullable(); // decides | pays | uses | technical | recommends
            $table->string('status', 16)->default('active');
            $table->string('style', 300)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['telegram_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_people');
        Schema::dropIfExists('clients');
    }
};
