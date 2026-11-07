<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search & AI visibility settings (singleton, id = 1): which crawlers robots.txt allows,
 * llms.txt, the public FAQ, opening hours for structured data, webmaster verification codes
 * and IndexNow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_settings', function (Blueprint $table) {
            $table->id();
            $table->json('crawlers')->nullable();          // bot => true (allowed) | false (blocked)
            $table->text('robots_extra')->nullable();
            $table->boolean('llms_enabled')->default(true);
            $table->text('llms_summary')->nullable();
            $table->boolean('faq_enabled')->default(false);
            $table->json('faq_types')->nullable();
            $table->string('opening_hours', 190)->nullable();
            $table->string('google_verification', 120)->nullable();
            $table->string('bing_verification', 120)->nullable();
            $table->string('yandex_verification', 120)->nullable();
            $table->boolean('indexnow_enabled')->default(false);
            $table->string('indexnow_key', 64)->nullable();
            $table->timestamp('indexnow_last_at')->nullable();
            $table->string('indexnow_last_result', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_settings');
    }
};
