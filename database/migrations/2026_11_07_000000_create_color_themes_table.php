<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('color_themes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('accent', 7);              // #rrggbb — buttons, links, highlights
            $table->string('neutral', 20)->default('gray'); // grey family for text, borders, backgrounds
            $table->boolean('is_locked')->default(false);   // the built-in default: cannot be edited or deleted
            $table->boolean('is_active')->default(false);   // exactly one theme is shown on the site
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('color_themes');
    }
};
