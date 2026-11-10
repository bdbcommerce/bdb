<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->string('department', 40);
            $table->string('employment_type', 20)->default('full_time');
            $table->json('location')->nullable();
            $table->string('salary', 120)->nullable();
            $table->json('description')->nullable();
            $table->json('requirements')->nullable();
            $table->json('offer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vacancy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vacancy_title')->nullable(); // kept when the vacancy is later deleted
            $table->string('department', 40)->nullable();
            $table->string('name', 120);
            $table->string('phone', 40);
            $table->string('email', 190)->nullable();
            $table->text('message')->nullable();
            $table->string('cv_path');
            $table->string('cv_name');
            $table->unsignedInteger('cv_size')->default(0);
            $table->string('locale', 5)->default('uz');
            $table->string('status', 20)->default('new');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('vacancies');
    }
};
