<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\User;
use App\Services\Ai\AiClient;
use Database\Seeders\AiModelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiModelsTest extends TestCase
{
    use RefreshDatabase;

    private const REPLY = [
        'reply' => 'Hello from the model', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
        'knowledge_id' => 0, 'topic' => 'greeting test', 'category' => 'faq',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => null,
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.alert_chat_id' => '777',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function model(array $attributes = []): AiModel
    {
        return AiModel::create($attributes + [
            'name' => 'Groq', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile',
            'base_url' => 'https://api.groq.com/openai/v1', 'api_key' => 'gsk_secret_groq_key_123',
            'tasks' => ['chat', 'telegram', 'admin'], 'priority' => 10, 'is_active' => true,
        ]);
    }

    private function ask()
    {
        return $this->postJson('/chat/message', ['locale' => 'en', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Hi']]])->assertOk();
    }

    private function openAiReply(array $json = self::REPLY): array
    {
        return ['choices' => [['message' => ['content' => '```json'."\n".json_encode($json)."\n```"], 'finish_reason' => 'stop']]];
    }

    public function test_openai_compatible_model_answers_the_chat(): void
    {
        $model = $this->model();
        Http::fake(['api.groq.com/*' => Http::response($this->openAiReply()), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->ask()->assertJson(['reply' => 'Hello from the model']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer gsk_secret_groq_key_123')
            && $r['model'] === 'llama-3.3-70b-versatile'
            && $r['messages'][0]['role'] === 'system'
            && str_contains($r['messages'][0]['content'], 'matches this JSON schema')
            && str_contains($r['messages'][0]['content'], '"type":"object"')
            && $r['response_format'] === ['type' => 'json_object']);
        $this->assertNotNull($model->fresh()->last_success_at);
    }

    public function test_failing_model_is_paused_and_the_backup_answers_with_an_alert(): void
    {
        $gemini = $this->model(['name' => 'Gemini Flash', 'provider' => 'gemini', 'model' => 'gemini-flash-latest', 'base_url' => null, 'api_key' => 'AIzaSecretGeminiKey', 'priority' => 10]);
        $groq = $this->model(['priority' => 20]);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota for AIzaSecretGeminiKey']], 429),
            'api.groq.com/*' => Http::response($this->openAiReply()),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertJson(['reply' => 'Hello from the model']);

        $gemini->refresh();
        $this->assertTrue($gemini->isPaused());
        $this->assertTrue($gemini->cooldown_until->between(now()->addMinutes(14), now()->addMinutes(16)));
        $this->assertStringContainsString('429', $gemini->last_error);
        $this->assertNotNull($groq->fresh()->last_success_at);

        $alert = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->first()[0];
        $this->assertStringContainsString('backup used', $alert['text']);
        $this->assertStringContainsString('Gemini Flash: Gemini returned HTTP 429', $alert['text']);
        $this->assertStringContainsString('Groq', $alert['text']);
        $this->assertStringNotContainsString('AIzaSecretGeminiKey', $alert['text']);

        // While paused, the next question goes straight to the backup.
        $this->ask();
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage')));
    }

    public function test_all_models_failing_gives_the_fallback_and_one_summary_alert(): void
    {
        $this->model(['name' => 'A', 'priority' => 10]);
        $this->model(['name' => 'B', 'priority' => 20, 'base_url' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest']);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'Invalid API Key', 'code' => 'invalid_api_key']], 401),
            'api.mistral.ai/*' => Http::response('Service Unavailable', 503),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertJson(['reply' => __('chat.error', [], 'en')]);

        $alerts = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]['text'])->values();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('All 2 AI models failed', $alerts[0]);
        $this->assertStringContainsString('A: A returned HTTP 401', $alerts[0]);
        $this->assertStringContainsString('B returned HTTP 503', $alerts[0]);
        $this->assertStringNotContainsString('gsk_secret_groq_key_123', $alerts[0]);
    }

    public function test_models_are_used_only_for_their_tasks(): void
    {
        $this->model(['name' => 'Telegram only', 'tasks' => ['telegram']]);
        $this->model(['name' => 'Off', 'is_active' => false]);

        $this->assertSame(['Telegram only'], AiModel::forTask('telegram')->pluck('name')->all());
        $this->assertSame(['Gemini (.env)'], AiModel::forTask('chat')->pluck('name')->all(), 'no chat model: .env fallback');
        $this->assertFalse(AiModel::forTask('chat')->first()->exists);
    }

    public function test_paused_models_are_tried_last(): void
    {
        $this->model(['name' => 'First', 'priority' => 10, 'cooldown_until' => now()->addMinutes(5)]);
        $this->model(['name' => 'Second', 'priority' => 20]);

        $this->assertSame(['Second', 'First'], AiModel::forTask('chat')->pluck('name')->all());
    }

    public function test_seeder_adds_free_presets_switched_off_and_is_idempotent(): void
    {
        $this->seed(AiModelSeeder::class);
        $this->seed(AiModelSeeder::class);

        $this->assertSame(count(AiModel::PRESETS), AiModel::count());
        $this->assertSame(0, AiModel::where('is_active', true)->count());
        $this->assertNull(AiModel::first()->api_key);
        // Seeded but inactive models don't replace the .env fallback.
        $this->assertFalse(AiModel::forTask('chat')->first()->exists);
    }

    public function test_settings_page_shows_models_presets_and_key_instructions(): void
    {
        $this->seed(AiModelSeeder::class);
        $groq = AiModel::where('model', 'llama-3.3-70b-versatile')->sole();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ai-models.index'))
            ->assertOk()->assertSee('Models in use')->assertSee('Groq Llama 3.3 70B')->assertSee('how to get the key')
            ->assertDontSee('Free models to add');

        $this->actingAs($admin)->get(route('admin.ai-models.edit', $groq))
            ->assertOk()->assertSee('How to get the key')
            ->assertSee('href="https://console.groq.com/keys"', false)
            ->assertSee('Create API Key');
    }

    public function test_admin_adds_a_model_from_a_preset_and_the_key_is_encrypted_and_hidden(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ai-models.create', ['preset' => 'groq-llama-70b']))
            ->assertOk()->assertSee('value="llama-3.3-70b-versatile"', false)->assertSee('How to get the key');

        $this->actingAs($admin)->post(route('admin.ai-models.store'), [
            'name' => 'Groq', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile', 'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => 'gsk_my_real_key_value', 'tasks' => ['chat', 'admin'], 'priority' => 10, 'is_active' => 1,
        ])->assertRedirect(route('admin.ai-models.index'));

        $model = AiModel::sole();
        $this->assertSame('gsk_my_real_key_value', $model->api_key);
        $this->assertStringNotContainsString('gsk_my_real_key_value', (string) AiModel::query()->toBase()->value('api_key'));
        $this->assertSame(['chat', 'admin'], $model->tasks);

        $this->actingAs($admin)->get(route('admin.ai-models.edit', $model))->assertOk()->assertDontSee('gsk_my_real_key_value');
        $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()->assertDontSee('gsk_my_real_key_value')->assertSee('key saved');

        // Blank key keeps it.
        $this->actingAs($admin)->put(route('admin.ai-models.update', $model), [
            'name' => 'Groq 2', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile', 'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => '', 'tasks' => ['chat'], 'priority' => 5, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('gsk_my_real_key_value', $model->fresh()->api_key);
    }

    public function test_an_active_model_needs_a_key_and_validation(): void
    {
        $admin = $this->admin();
        $base = ['name' => 'X', 'provider' => 'openai', 'model' => 'm', 'base_url' => 'https://api.groq.com/openai/v1', 'tasks' => ['chat'], 'priority' => 10];

        $this->actingAs($admin)->post(route('admin.ai-models.store'), $base + ['is_active' => 1])->assertSessionHasErrors('api_key');
        $this->actingAs($admin)->post(route('admin.ai-models.store'), $base)->assertSessionHasNoErrors(); // off without key is fine

        $this->actingAs($admin)->post(route('admin.ai-models.store'), [
            'name' => '', 'provider' => 'bogus', 'model' => 'bad name; rm', 'base_url' => 'not a url', 'tasks' => ['nope'], 'priority' => 0,
        ])->assertSessionHasErrors(['name', 'provider', 'model', 'tasks.0', 'priority']);

        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['base_url' => '']))
            ->assertSessionHasErrors('base_url');
    }

    public function test_test_button_and_resume(): void
    {
        $model = $this->model(['cooldown_until' => now()->addMinutes(10)]);
        Http::fake(['api.groq.com/*' => Http::sequence()->push($this->openAiReply(['ok' => true]))->push(['error' => ['message' => 'Invalid API Key']], 401)]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.ai-models.test', $model))->assertSessionHas('success', 'Groq works.');
        $this->assertFalse($model->fresh()->isPaused());

        $this->actingAs($admin)->post(route('admin.ai-models.test', $model))
            ->assertSessionHas('ai_error', fn ($m) => str_contains($m, 'HTTP 401') && ! str_contains($m, 'gsk_secret'));
        $this->assertTrue($model->fresh()->isPaused());

        $this->actingAs($admin)->post(route('admin.ai-models.resume', $model))->assertRedirect();
        $this->assertFalse($model->fresh()->isPaused());

        // Test never sends Telegram alerts.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org'));
    }

    public function test_settings_are_admin_only(): void
    {
        $model = $this->model();

        $this->get(route('admin.ai-models.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.ai-models.test', $model))->assertRedirect(route('admin.login'));
        $this->delete(route('admin.ai-models.destroy', $model))->assertRedirect(route('admin.login'));
        $this->assertNotNull($model->fresh());
    }

    public function test_json_helpers(): void
    {
        $this->assertSame(['a' => 1], AiClient::decodeJson("```json\n{\"a\":1}\n```"));
        $this->assertSame(['a' => 1], AiClient::decodeJson('Sure! {"a":1} Hope this helps.'));
        $this->assertNull(AiClient::decodeJson('no json here'));
        $this->assertSame(
            ['type' => 'object', 'properties' => ['x' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['x']],
            AiClient::jsonSchema(['type' => 'OBJECT', 'properties' => ['x' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['x'], 'propertyOrdering' => ['x']]),
        );
    }
}
