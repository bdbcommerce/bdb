<?php

use App\Support\AiEmployeeTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Test cases: a scripted customer (one message per turn) and a checklist the answers are graded on.
        Schema::hasTable('ai_test_cases') || Schema::create('ai_test_cases', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('ai_employee_id')->nullable()->constrained()->nullOnDelete(); // null = every employee
            $table->string('via', 10)->default('bot');
            $table->json('turns');
            $table->json('criteria');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        // One run of a case against one prompt version (the prompt text's hash: an edited draft needs a new run).
        Schema::hasTable('ai_test_runs') || Schema::create('ai_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_test_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_prompt_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('prompt_version');
            $table->string('prompt_hash', 32);
            $table->string('status', 10)->default('queued'); // queued | running | done | failed
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('transcript')->nullable();
            $table->json('results')->nullable();
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 5)->nullable();
            $table->string('started_by', 100)->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index(['ai_employee_prompt_id', 'prompt_hash']);
        });

        if (DB::table('ai_test_cases')->exists()) {
            return;
        }
        $ids = DB::table('ai_employees')->pluck('id', 'name');
        DB::transaction(function () use ($ids) {
            foreach (AiEmployeeTemplates::TEST_CASES as $case) {
                DB::table('ai_test_cases')->insert([
                    'name' => $case['name'], 'ai_employee_id' => $case['employee'] ? ($ids[$case['employee']] ?? null) : null, 'via' => 'bot',
                    'turns' => json_encode($case['turns'], JSON_UNESCAPED_UNICODE), 'criteria' => json_encode($case['criteria'], JSON_UNESCAPED_UNICODE),
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_test_runs');
        Schema::dropIfExists('ai_test_cases');
    }
};
