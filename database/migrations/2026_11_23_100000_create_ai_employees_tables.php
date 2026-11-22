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
        // AI employees: a name, a role and a temperament per card; their role prompts are versioned.
        Schema::hasTable('ai_employees') || Schema::create('ai_employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('role', 80);
            $table->string('kind', 20)->default('sales');
            $table->string('status', 10)->default('draft');
            $table->string('audience', 200)->nullable();
            $table->json('channels')->nullable();
            $table->json('scales')->nullable();
            $table->json('traits')->nullable();
            $table->text('manner')->nullable();
            $table->text('say')->nullable();
            $table->text('avoid')->nullable();
            $table->text('handover')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::hasTable('ai_employee_prompts') || Schema::create('ai_employee_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('prompt');
            $table->string('note', 200)->nullable();
            $table->string('status', 10)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->string('author', 100)->nullable();
            $table->timestamps();
            $table->unique(['ai_employee_id', 'version']);
        });
        // The platform rules (first prompt layer for every AI employee); null = AiEmployeeTemplates::PLATFORM_RULES.
        Schema::hasColumn('sales_assistant_settings', 'platform_rules') || Schema::table('sales_assistant_settings', function (Blueprint $table) {
            $table->text('platform_rules')->nullable();
        });

        // The first three AI employees, each with a published role prompt (v1).
        if (DB::table('ai_employees')->exists()) {
            return;
        }
        $templates = AiEmployeeTemplates::all();
        DB::transaction(fn () => $this->seed($templates));
    }

    private function seed(array $templates): void
    {
        foreach (AiEmployeeTemplates::DEFAULTS as $key) {
            $t = $templates[$key];
            $id = DB::table('ai_employees')->insertGetId([
                'name' => $t['name'], 'role' => $t['role'], 'kind' => $t['kind'], 'status' => $t['status'], 'audience' => $t['audience'],
                'channels' => json_encode($t['channels']), 'scales' => json_encode($t['scales']), 'traits' => json_encode($t['traits'], JSON_UNESCAPED_UNICODE),
                'manner' => $t['manner'], 'say' => $t['say'], 'avoid' => $t['avoid'], 'handover' => $t['handover'], 'sort' => $t['sort'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ai_employee_prompts')->insert([
                'ai_employee_id' => $id, 'version' => 1, 'prompt' => $t['prompt'], 'note' => 'Default prompt', 'status' => 'published',
                'published_at' => now(), 'author' => 'Default', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_employee_prompts');
        Schema::dropIfExists('ai_employees');
        if (Schema::hasColumn('sales_assistant_settings', 'platform_rules')) {
            Schema::table('sales_assistant_settings', fn (Blueprint $table) => $table->dropColumn('platform_rules'));
        }
    }
};
