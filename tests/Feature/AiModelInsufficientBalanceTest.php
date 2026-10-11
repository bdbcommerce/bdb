<?php

namespace Tests\Feature;

use App\Console\Commands\AiCheckCommand;
use App\Models\AiModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** HTTP 402 (no money on the account): the model pauses for an hour and the Test flash says what to do. */
class AiModelInsufficientBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-deepseek_SECRET_key_123456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.key' => null, 'services.telegram.bot_token' => null]);
    }

    private function model(): AiModel
    {
        return AiModel::create([
            'name' => 'DeepSeek <b>', 'provider' => 'openai', 'model' => 'deepseek-chat',
            'base_url' => 'https://api.deepseek.com/v1', 'api_key' => self::KEY,
            'tasks' => ['chat'], 'priority' => 10, 'is_active' => true,
        ]);
    }

    public function test_402_pauses_for_an_hour_and_flash_shows_the_hint_without_the_key(): void
    {
        $model = $this->model();
        Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'Insufficient Balance '.self::KEY]], 402)]);
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->post(route('admin.ai-models.test', $model));

        $response->assertRedirect(route('admin.ai-models.index'))->assertSessionHas('ai_error', function ($message) {
            return str_contains($message, 'Insufficient Balance')
                && str_contains($message, PHP_EOL.'What to do: The account has no money left.')
                && ! str_contains($message, self::KEY);
        });

        $fresh = $model->fresh();
        $this->assertTrue($fresh->isPaused());
        $this->assertTrue($fresh->cooldown_until->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->assertStringNotContainsString(self::KEY, (string) $fresh->last_error);

        $page = $this->actingAs($admin)->withSession(['ai_error' => session('ai_error')])->get(route('admin.ai-models.index'));
        $page->assertOk()->assertSee('whitespace-pre-line', false)->assertSee('What to do: The account has no money left.')
            ->assertDontSee(self::KEY)->assertDontSee('DeepSeek <b>', false)->assertSee('DeepSeek &lt;b&gt;', false);
    }

    public function test_hint_is_public_and_covers_402(): void
    {
        $this->assertStringContainsString('Top up', AiCheckCommand::hint('HTTP 402: Insufficient Balance'));
        $this->assertStringContainsString('Top up', AiCheckCommand::hint('Insufficient Balance'));
        $this->assertStringContainsString('Quota', AiCheckCommand::hint('HTTP 429'));
    }
}
