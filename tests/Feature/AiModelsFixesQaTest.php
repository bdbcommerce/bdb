<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\AiModel;
use App\Models\User;
use App\Services\ChatAssistant;
use Database\Seeders\AiModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Re-verification of the AI settings fixes: wrong-format fallback, DeepSeek presets/URL,
 * error labels and CRLF normalisation of instruction documents.
 */
class AiModelsFixesQaTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk_FIXES_secret_key_123456';

    private const REPLY = [
        'reply' => 'Backup says hi', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
        'knowledge_id' => 0, 'topic' => 'greeting', 'category' => 'faq',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => null,
            'services.telegram.bot_token' => 'BOT123456',
            'services.telegram.alert_chat_id' => '777',
        ]);
    }

    private function model(array $attributes = []): AiModel
    {
        return AiModel::create($attributes + [
            'name' => 'Primary', 'provider' => 'openai', 'model' => 'm-primary',
            'base_url' => 'https://primary.example/v1', 'api_key' => self::KEY,
            'tasks' => ['chat', 'telegram', 'admin'], 'priority' => 10, 'is_active' => true,
        ]);
    }

    private function openAi(array $content): array
    {
        return ['choices' => [['message' => ['content' => json_encode($content)], 'finish_reason' => 'stop']]];
    }

    private function sentAlerts(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'sendMessage'))
            ->map(fn (Request $r) => $r['text'])->values()->all();
    }

    public function test_wrong_format_first_model_falls_back_to_second_and_alert_names_backup(): void
    {
        $this->model(['name' => 'Broken', 'base_url' => 'https://broken.example/v1', 'priority' => 10]);
        $this->model(['name' => 'Backup', 'base_url' => 'https://backup.example/v1', 'priority' => 20]);

        Http::fake([
            'broken.example/*' => Http::response($this->openAi([])),
            'backup.example/*' => Http::response($this->openAi(self::REPLY)),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $assistant = app(ChatAssistant::class);
        $result = $assistant->reply([['role' => 'user', 'content' => 'Привет, salom']], 'ru');

        $this->assertSame('Backup says hi', $result['reply']);
        $this->assertSame('Backup', $assistant->lastModel()->name);

        $broken = AiModel::where('name', 'Broken')->first();
        $this->assertStringContainsString('answered in the wrong format', $broken->last_error);
        $this->assertEqualsWithDelta(now()->addMinutes(2)->timestamp, $broken->cooldown_until->timestamp, 2);
        $this->assertFalse(AiModel::where('name', 'Backup')->first()->isPaused());

        $alerts = $this->sentAlerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('answered in the wrong format', $alerts[0]);
        $this->assertStringContainsString('The backup model <b>Backup</b> answered', $alerts[0]);
        $this->assertStringNotContainsString(self::KEY, $alerts[0]);
    }

    public function test_blank_reply_string_is_also_wrong_format(): void
    {
        $this->model(['name' => 'Blank', 'base_url' => 'https://blank.example/v1', 'priority' => 10]);
        $this->model(['name' => 'Backup', 'base_url' => 'https://backup.example/v1', 'priority' => 20]);
        Http::fake([
            'blank.example/*' => Http::response($this->openAi(['reply' => '   '] + self::REPLY)),
            'backup.example/*' => Http::response($this->openAi(self::REPLY)),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $assistant = app(ChatAssistant::class);
        $this->assertSame('Backup says hi', $assistant->reply([['role' => 'user', 'content' => 'hi']], 'en')['reply']);
        $this->assertSame('Backup', $assistant->lastModel()->name);
    }

    public function test_all_models_wrong_format_gives_fallback_reply_and_summary_lists_tried_models(): void
    {
        config(['services.gemini.model' => 'gemini-env-model']);
        $this->model(['name' => 'A', 'model' => 'model-a', 'base_url' => 'https://a.example/v1']);
        $this->model(['name' => 'B', 'model' => 'model-b', 'base_url' => 'https://b.example/v1', 'priority' => 20]);
        Http::fake([
            'a.example/*' => Http::response($this->openAi([])),
            'b.example/*' => Http::response($this->openAi(['reply' => 7])),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $result = app(ChatAssistant::class)->reply([['role' => 'user', 'content' => 'hi']], 'uz');

        $this->assertIsString($result['reply']);
        $this->assertNotSame('', trim($result['reply']));
        $alerts = $this->sentAlerts();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('All 2 AI models failed', $alerts[0]);
        $this->assertStringContainsString('model-a', $alerts[0]);
        $this->assertStringContainsString('model-b', $alerts[0]);
        $this->assertStringNotContainsString('gemini-env-model', $alerts[0]);
    }

    public function test_deepseek_base_url_without_v1_builds_chat_completions_url(): void
    {
        foreach (['https://api.deepseek.com', 'https://api.deepseek.com/'] as $i => $baseUrl) {
            AiModel::query()->delete();
            $this->model(['name' => 'DeepSeek', 'model' => 'deepseek-chat', 'base_url' => $baseUrl]);
            Http::fake([
                'api.deepseek.com/*' => Http::response($this->openAi(self::REPLY)),
                'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            ]);

            $this->assertSame('Backup says hi', app(ChatAssistant::class)->reply([['role' => 'user', 'content' => 'hi']], 'en')['reply']);
            Http::assertSent(fn (Request $r) => $r->url() === 'https://api.deepseek.com/chat/completions'
                && $r['model'] === 'deepseek-chat'
                && $r->hasHeader('Authorization', 'Bearer '.self::KEY));
        }

        $urls = collect(Http::recorded())->map(fn ($p) => $p[0]->url())->reject(fn ($u) => str_contains($u, 'telegram'))->unique()->values()->all();
        $this->assertSame(['https://api.deepseek.com/chat/completions'], $urls);
    }

    public function test_new_presets_exist_and_create_form_prefills_them(): void
    {
        $this->assertSame('deepseek/deepseek-chat-v3-0324:free', AiModel::PRESETS['openrouter-deepseek-free']['model']);
        $this->assertSame('https://openrouter.ai/api/v1', AiModel::PRESETS['openrouter-deepseek-free']['base_url']);
        // Only free models are offered: the paid official DeepSeek API is not a preset.
        $this->assertArrayNotHasKey('deepseek', AiModel::PRESETS);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.ai-models.create', ['preset' => 'openrouter-deepseek-free']))
            ->assertOk()->assertSee('deepseek/deepseek-chat-v3-0324:free')->assertSee('https://openrouter.ai/keys');
        $this->actingAs($admin)->get(route('admin.ai-models.create', ['preset' => 'no-such']))->assertOk();
        $this->actingAs($admin)->get('/admin/ai-models/create?preset[]=openrouter-deepseek-free')->assertOk()->assertDontSee('deepseek-chat-v3-0324:free');
    }

    public function test_preset_seeder_is_idempotent_and_keeps_admin_edits(): void
    {
        $this->seed(AiModelSeeder::class);
        $count = AiModel::count();
        $this->assertSame(count(AiModel::PRESETS), $count);
        $deepseek = AiModel::where('model', 'deepseek/deepseek-chat-v3-0324:free')->sole();
        $this->assertFalse($deepseek->is_active);
        $this->assertNull($deepseek->api_key);

        $deepseek->update(['api_key' => self::KEY, 'is_active' => true, 'priority' => 3]);
        $this->seed(AiModelSeeder::class);

        $this->assertSame($count, AiModel::count());
        $deepseek->refresh();
        $this->assertTrue($deepseek->is_active);
        $this->assertSame(self::KEY, $deepseek->api_key);
        $this->assertSame(3, $deepseek->priority);
    }

    public function test_error_label_prefers_first_string_among_status_type_code(): void
    {
        $cases = [
            [['error' => ['status' => 500, 'type' => 'invalid_request_error', 'code' => 'model_not_found', 'message' => 'm']], 'HTTP 404 invalid_request_error'],
            [['error' => ['code' => 'model_not_found', 'message' => 'm']], 'HTTP 404 model_not_found'],
            [['error' => ['status' => 'NOT_FOUND', 'code' => 404, 'message' => 'm']], 'HTTP 404 NOT_FOUND'],
            [['error' => ['type' => '', 'code' => 404, 'message' => 'm']], 'returned HTTP 404: m'],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$body]) {
            $sequence->push($body, 404);
        }
        Http::fake(['primary.example/*' => $sequence, 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        foreach ($cases as [, $expected]) {
            AiModel::query()->delete();
            $error = app(ChatAssistant::class)->testModel($this->model());

            $this->assertStringContainsString($expected, $error);
        }
    }

    public function test_mark_failure_masks_this_models_key_even_before_it_is_in_the_table(): void
    {
        $model = $this->model();
        $model->markFailure('Invalid key '.self::KEY.' provided', 401);

        $this->assertStringNotContainsString(self::KEY, $model->fresh()->last_error);
        $this->assertStringContainsString('***', $model->fresh()->last_error);
    }

    public function test_crlf_is_normalised_for_main_prompt_and_documents(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $main = AiInstruction::main();
        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $main), ['content' => "Line one\r\nЛиния два\r\nO‘zbek"])->assertRedirect();
        $this->assertSame("Line one\nЛиния два\nO‘zbek", $main->fresh()->content);

        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [
            'title' => 'Doc', 'kind' => 'info',
            'channel' => 'all', 'content' => "a\r\n\r\nb", 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $doc = AiInstruction::where('title', 'Doc')->firstOrFail();
        $this->assertSame("a\n\nb", $doc->content);

        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $doc), [
            'title' => 'Doc', 'kind' => $doc->kind, 'channel' => 'all', 'content' => "x\r\ny", 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame("x\ny", $doc->fresh()->content);
    }
}
