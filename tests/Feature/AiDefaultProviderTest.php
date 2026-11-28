<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\User;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** With no model switched on in AI settings the default is Claude; Gemini only with AI_DEFAULT_PROVIDER=gemini. */
class AiDefaultProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_claude_and_needs_the_anthropic_key(): void
    {
        Http::fake();
        config(['services.ai.default' => 'anthropic', 'services.ai.model' => 'claude-opus-5-5', 'services.gemini.key' => 'gemini-key', 'services.anthropic.key' => null]);

        $model = AiModel::forTask('admin')->sole();
        $this->assertSame(['anthropic', 'claude-opus-5-5', 'Claude (.env)'], [$model->provider, $model->model, $model->name]);

        // A Gemini key alone is not used any more.
        $this->assertFalse(AiModel::available());
        $result = app(AiClient::class)->call($model, 'sys', [['role' => 'user', 'content' => 'hi']], ['type' => 'OBJECT', 'properties' => []], 0.1, 50);
        $this->assertSame([null, 'ANTHROPIC_API_KEY is not set in .env'], [$result['data'], $result['reason']]);
        Http::assertNothingSent();

        config(['services.anthropic.key' => 'sk-ant-test']);
        $this->assertTrue(AiModel::available());
        $this->assertSame('sk-ant-test', AiModel::fromEnv()->key());
    }

    public function test_a_model_switched_on_in_ai_settings_wins_over_the_default(): void
    {
        config(['services.ai.default' => 'anthropic']);
        AiModel::create(['name' => 'Groq', 'provider' => 'openai', 'model' => 'llama', 'base_url' => 'https://api.groq.com/openai/v1', 'api_key' => 'k', 'tasks' => ['admin'], 'priority' => 10, 'is_active' => true]);

        $this->assertSame('Groq', AiModel::forTask('admin')->sole()->name);
        $this->assertSame('Claude (.env)', AiModel::forTask('chat')->sole()->name);
    }

    public function test_gemini_default_is_kept_only_on_request(): void
    {
        config(['services.ai.default' => 'gemini', 'services.gemini.key' => 'gemini-key', 'services.gemini.model' => 'gemini-x']);

        $this->assertSame(['gemini', 'gemini-x'], [AiModel::fromEnv()->provider, AiModel::fromEnv()->model]);
        $this->assertTrue(AiModel::available());
    }

    public function test_ai_settings_page_names_the_default(): void
    {
        config(['services.ai.default' => 'anthropic', 'services.anthropic.key' => null]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()
            ->assertSee('assistant uses Claude')->assertSee('claude-opus-5-5')->assertSee('Without a key the AI cannot answer');
    }
}
