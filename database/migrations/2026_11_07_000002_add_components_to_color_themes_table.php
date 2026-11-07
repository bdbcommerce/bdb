<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('color_themes', function (Blueprint $table) {
            // Optional per-component colours (header, nav, buttons, links, footer): key => #rrggbb.
            // Missing keys follow the accent / neutral automatically.
            $table->json('components')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('color_themes', function (Blueprint $table) {
            $table->dropColumn('components');
        });
    }
};
