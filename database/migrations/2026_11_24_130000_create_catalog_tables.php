<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product catalog for the AI sales team, loaded from 1C (docs/1c-catalog-http-service.md):
 * pushed to POST /api/catalog or pulled from a 1C HTTP service, always the full list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('brand', 64);
            $table->string('model', 100);
            $table->string('category', 32)->index();
            $table->json('name');
            $table->json('prices'); // end_user / dealer / partner, UZS incl. VAT
            $table->string('stock_status', 16);
            $table->integer('stock_qty')->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->integer('warranty_months')->nullable();
            $table->json('params')->nullable();
            $table->json('use_cases')->nullable();
            $table->json('accessories')->nullable();
            $table->boolean('is_active')->default(true); // "active" from 1C
            $table->boolean('in_feed')->default(true); // false = missing from the latest full load
            $table->timestamps();
        });

        // Partners and dealers from the load, one row per phone (replaced by every load that has "clients").
        Schema::create('catalog_clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();
            $table->string('price_group', 16);
            $table->string('company', 200);
            $table->string('inn', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_settings', function (Blueprint $table) {
            $table->id();
            $table->string('source', 8)->default('push'); // push | pull
            $table->string('token_hash', 64)->nullable();
            $table->string('token_hint', 8)->nullable();
            $table->string('pull_url', 500)->nullable();
            $table->string('pull_username', 100)->nullable();
            $table->text('pull_password')->nullable(); // encrypted
            $table->unsignedInteger('max_age_hours')->default(24);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('catalog_loads', function (Blueprint $table) {
            $table->id();
            $table->string('source', 8); // push | pull
            $table->string('trigger', 16)->default('schedule'); // schedule | manual | push
            $table->string('status', 16); // ok | refused | failed
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('hidden')->default(0);
            $table->unsignedInteger('clients')->nullable();
            $table->json('rejected')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_loads');
        Schema::dropIfExists('catalog_settings');
        Schema::dropIfExists('catalog_clients');
        Schema::dropIfExists('catalog_products');
    }
};
