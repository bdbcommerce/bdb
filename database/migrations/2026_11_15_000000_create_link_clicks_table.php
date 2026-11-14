<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clicks on outbound brand links (official website, online shop) — Admin → Site analytics.
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('brand_name', 200)->nullable(); // kept if the brand is deleted
            $table->string('target', 16);                   // website | shop
            $table->string('locale', 2)->nullable();
            $table->string('device', 10)->nullable();
            $table->string('visitor_hash', 16);             // daily-rotating, no IP or cookie
            $table->timestamp('clicked_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
    }
};
