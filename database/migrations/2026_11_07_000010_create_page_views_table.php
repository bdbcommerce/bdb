<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site analytics: one row per public page view (people and crawlers). No IP address or cookie is
 * stored — visitor_hash is a daily-rotating hash, so visitors can only be counted per day.
 * Pruned after PageView::KEEP_DAYS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->timestamp('visited_at')->index();
            $table->string('path', 255);
            $table->string('route', 64)->nullable();
            $table->string('locale', 2)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->string('source', 16)->default('direct');   // direct | search | ai | social | other | internal
            $table->string('utm_source', 64)->nullable();
            $table->string('device', 8)->nullable();           // mobile | tablet | desktop
            $table->char('visitor_hash', 16)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->string('bot', 40)->nullable();
            $table->index(['is_bot', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
