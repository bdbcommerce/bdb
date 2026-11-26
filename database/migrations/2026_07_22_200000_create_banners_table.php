<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->json('badge')->nullable();
            $table->json('title');
            $table->json('subtitle')->nullable();
            $table->json('points')->nullable();
            $table->json('button_text')->nullable();
            $table->string('button_url')->nullable();
            $table->boolean('show_ai_button')->default(true);
            $table->string('image')->nullable();
            $table->string('illustration', 32)->default('scanner');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
