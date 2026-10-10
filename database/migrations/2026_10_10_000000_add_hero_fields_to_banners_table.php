<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banners now drive the home page hero, so they need the hero's extra parts:
 * a small badge line above the title, bullet points, and the "Ask AI" button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->json('badge')->nullable()->after('id');
            $table->json('points')->nullable()->after('subtitle');
            $table->boolean('show_ai_button')->default(true)->after('button_url');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['badge', 'points', 'show_ai_button']);
        });
    }
};
