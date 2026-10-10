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
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_leads');
    }
};
