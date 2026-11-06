<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which built-in animated illustration a hero slide shows when it has no
 * uploaded image (see Banner::ILLUSTRATIONS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->string('illustration', 32)->default('scanner')->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn('illustration');
        });
    }
};
