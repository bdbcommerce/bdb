<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\AiModel;
use App\Models\User;
use App\Services\AiErrorAlerts;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * QA edge cases for AI settings (multiple models, fallback, keys) and the editable main prompt.
 */
class AiModelsQaTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'gsk_SUPER_secret_key_987654';

    private const REPLY = [
        'reply' => 'Hi there', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
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

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function model(array $attributes = []): AiModel
    {
        return AiModel::create($attributes + [
            'name' => 'Groq', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile',
            'base_url' => 'https://api.groq.com/openai/v1', 'api_key' => self::KEY,
            'tasks' => ['chat', 'telegram', 'admin'], 'priority' => 10, 'is_active' => true,
        ]);
    }

    private function openAi(array|string $content): array
    {
        return ['choices' => [['message' => ['content' => is_string($content) ? $content : json_encode($content)], 'finish_reason' => 'stop']]];
    }

    private function history(): array
    {
        return [['role' => 'user', 'content' => 'Salom, printer narxi qancha? Привет']];
    }

    // ---- secrets ----

    public function test_key_echoed_in_provider_error_is_not_shown_on_settings_page(): void
    {
        $model = $this->model();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Invalid API Key: '.self::KEY, 'code' => 'invalid_api_key']], 401), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        app(ChatAssistant::class)->reply($this->history(), 'en');

        $this->actingAs($this->admin())->get(route('admin.ai-models.index'))
            ->assertOk()->assertDontSee(self::KEY);
    }

    public function test_key_echoed_in_provider_error_is_not_stored_in_last_error(): void
    {
        $model = $this->model();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'Invalid API Key: '.self::KEY]], 401), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        app(ChatAssistant::class)->reply($this->history(), 'en');

        $this->assertStringNotContainsString(self::KEY, (string) $model->fresh()->last_error);
    }

    public function test_key_never_in_alert_last_error_or_test_flash(): void
    {
        $model = $this->model();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'bad key '.self::KEY]], 401), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $assistant = app(ChatAssistant::class);
        $assistant->reply($this->history(), 'en');
        $this->assertStringNotContainsString(self::KEY, (string) $assistant->lastError());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && ! str_contains($r->body(), self::KEY));

        $model->forceFill(['cooldown_until' => null])->save();
        $this->actingAs($this->admin())->post(route('admin.ai-models.test', $model))
            ->assertSessionHas('ai_error', fn ($m) => ! str_contains($m, self::KEY));
    }

    public function test_key_hidden_from_array_and_json_and_forms(): void
    {
        $model = $this->model();
        $this->assertArrayNotHasKey('api_key', $model->toArray());
        $this->assertStringNotContainsString(self::KEY, $model->toJson());
        $this->assertStringNotContainsString(self::KEY, (string) \DB::table('ai_models')->value('api_key'), 'stored encrypted');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.ai-models.edit', $model))->assertOk()->assertDontSee(self::KEY);
        $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()->assertDontSee(self::KEY);
    }

    public function test_ai_check_command_output_has_no_key_and_sends_no_alert(): void
    {
        $this->model();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'nope '.self::KEY]], 401), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->artisan('ai:check')
            ->doesntExpectOutputToContain(self::KEY)
            ->expectsOutputToContain('HTTP 401')
            ->assertExitCode(1);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r->url(), 'sendMessage'));
    }

    public function test_without_secrets_masks_every_model_key(): void
    {
        $this->model(['api_key' => 'key-one-abcdef']);
        $this->model(['name' => 'B', 'api_key' => 'key-two-ghijkl']);

        $this->assertSame('*** and ***', AiErrorAlerts::withoutSecrets('key-one-abcdef and key-two-ghijkl'));
    }

    // ---- fallback / cooldowns ----

    public function test_cooldown_durations_per_status(): void
    {
        $this->travelTo(now()->startOfMinute());
        foreach ([429 => 15, 401 => 60, 400 => 60, 403 => 60, 404 => 60, 500 => 2, 503 => 2] as $status => $minutes) {
            $model = $this->model(['name' => "S{$status}"]);
            $model->markFailure('x', $status);
            $this->assertEquals(now()->addMinutes($minutes)->timestamp, $model->fresh()->cooldown_until->timestamp, "status {$status}");
            $model->delete();
        }
        $model = $this->model();
        $model->markFailure('timeout', null);
        $this->assertEquals(now()->addMinutes(2)->timestamp, $model->fresh()->cooldown_until->timestamp);
    }

    public function test_all_paused_models_are_still_tried_in_priority_order(): void
    {
        $this->model(['name' => 'A', 'priority' => 20, 'cooldown_until' => now()->addMinutes(5)]);
        $this->model(['name' => 'B', 'priority' => 10, 'cooldown_until' => now()->addMinutes(5)]);

        $this->assertSame(['B', 'A'], AiModel::forTask('chat')->pluck('name')->all());
    }

    public function test_empty_table_vs_only_inactive_rows(): void
    {
        $this->assertFalse(AiModel::available());
        $this->assertFalse(AiModel::forTask('chat')->first()->exists);

        $this->model(['is_active' => false]);
        $this->assertFalse(AiModel::available());
        $this->assertSame(['Gemini (.env)'], AiModel::forTask('chat')->pluck('name')->all());

        config(['services.gemini.key' => 'AIzaENVKEY123']);
        $this->assertTrue(AiModel::available());

        // No key anywhere: chat gives the fallback reply without any HTTP call to an AI.
        config(['services.gemini.key' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $result = app(ChatAssistant::class)->reply($this->history(), 'uz');
        $this->assertIsString($result['reply']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage') || str_contains($r->url(), 'groq'));
    }

    public function test_bad_base_url_non_json_and_fenced_json_fall_through_to_the_next_model(): void
    {
        $this->model(['name' => 'Dead', 'base_url' => 'https://dead.invalid/v1', 'priority' => 10]);
        $this->model(['name' => 'Html', 'base_url' => 'https://html.example/v1', 'priority' => 20]);
        $this->model(['name' => 'Fenced', 'base_url' => 'https://fenced.example/v1', 'priority' => 30]);

        Http::fake([
            'dead.invalid/*' => fn () => throw new ConnectionException('Could not resolve host'),
            'html.example/*' => Http::response('<html>Bad gateway</html>', 200),
            'fenced.example/*' => Http::response($this->openAi("```json\n".json_encode(self::REPLY)."\n```")),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $assistant = app(ChatAssistant::class);
        $result = $assistant->reply($this->history(), 'ru');

        $this->assertSame('Hi there', $result['reply']);
        $this->assertSame('Fenced', $assistant->lastModel()->name);
        $this->assertTrue(AiModel::where('name', 'Dead')->first()->isPaused());
        $this->assertTrue(AiModel::where('name', 'Html')->first()->isPaused());
    }

    public function test_wrong_types_never_crash(): void
    {
        $this->model();
        foreach ([
            ['reply' => 5, 'suggestions' => 'x', 'offer_callback' => 'yes', 'answered' => [], 'knowledge_id' => 'abc', 'topic' => ['a'], 'category' => 7],
            ['reply' => 'ok', 'suggestions' => [['nested']], 'offer_callback' => null, 'answered' => null, 'knowledge_id' => [], 'topic' => null, 'category' => null],
            [],
            [1, 2, 3],
        ] as $payload) {
            Cache::flush();
            AiModel::query()->update(['cooldown_until' => null]);
            Http::fake(['api.groq.com/*' => Http::response($this->openAi($payload)), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
            $result = app(ChatAssistant::class)->reply($this->history(), 'en');
            $this->assertIsString($result['reply']);
            $this->assertIsArray($result['suggestions']);
            $this->assertIsBool($result['offer_callback']);
        }
    }

    public function test_openai_error_code_as_int_and_message_as_array(): void
    {
        $model = $this->model();
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['code' => 429, 'message' => ['nested' => 'x'], 'type' => 'rate_limit']], 429), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $error = app(ChatAssistant::class)->testModel($model);

        $this->assertStringContainsString('HTTP 429', $error);
        $this->assertEquals(now()->addMinutes(15)->timestamp, $model->fresh()->cooldown_until->timestamp, '', 2);
    }

    public function test_all_failed_alert_model_line(): void
    {
        config(['services.gemini.model' => 'gemini-env-model']);
        $this->model(['name' => 'A', 'model' => 'model-a']);
        $this->model(['name' => 'B', 'model' => 'model-b', 'priority' => 20]);
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'down']], 500), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        app(ChatAssistant::class)->reply($this->history(), 'en');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'All 2 AI models failed'));
        // Observation: which "Model:" line is shown in the summary alert.
        $sent = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'sendMessage'));
        $this->assertStringNotContainsString('gemini-env-model', $sent['text'], 'summary alert names the .env model although it was not tried');
    }

    // ---- task routing ----

    public function test_task_routing_web_telegram_admin(): void
    {
        $this->model(['name' => 'Web', 'base_url' => 'https://web.example/v1', 'tasks' => ['chat']]);
        $this->model(['name' => 'Tg', 'base_url' => 'https://tg.example/v1', 'tasks' => ['telegram']]);
        $this->model(['name' => 'Adm', 'base_url' => 'https://adm.example/v1', 'tasks' => ['admin']]);
        Http::fake([
            '*.example/*' => Http::response($this->openAi(self::REPLY + ['answered_ids' => []])),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $a = app(ChatAssistant::class);

        $a->reply($this->history(), 'en');
        $this->assertSame('Web', $a->lastModel()->name);
        $a->reply($this->history(), 'en', null, brief: true);
        $this->assertSame('Tg', $a->lastModel()->name);
        $a->suggestStaffReply($this->history(), 'en');
        $this->assertSame('Adm', $a->lastModel()->name);
    }

    // ---- controller / validation ----

    public function test_preset_query_as_array_does_not_500(): void
    {
        $this->actingAs($this->admin())->get('/admin/ai-models/create?preset[]=groq-llama-70b')->assertOk();
    }

    public function test_preset_steps_render_links_and_escape(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.ai-models.create', ['preset' => 'openrouter-free']))->assertOk()->getContent();
        $this->assertStringContainsString('<a href="https://openrouter.ai/models?max_price=0"', $html);
        $this->assertStringContainsString('<a href="https://openrouter.ai/keys"', $html);
        $html = $this->actingAs($this->admin())->get(route('admin.ai-models.create', ['preset' => 'groq-llama-70b']))->getContent();
        $this->assertStringContainsString('<a href="https://console.groq.com/keys"', $html, 'closing bracket not part of link');
        $this->assertStringContainsString('&quot;Create API Key&quot;', $html);
    }

    public function test_validation_edge_cases_and_old_input(): void
    {
        $admin = $this->admin();
        $base = ['name' => 'X', 'provider' => 'openai', 'model' => 'm', 'base_url' => 'https://api.example.com/v1', 'tasks' => ['chat'], 'priority' => 10];

        $this->actingAs($admin)->from(route('admin.ai-models.create'))->post(route('admin.ai-models.store'), array_merge($base, ['name' => ['a'], 'api_key' => ['x'], 'tasks' => 'chat', 'priority' => 1000]))
            ->assertSessionHasErrors(['name', 'api_key', 'tasks', 'priority']);
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['tasks' => [['chat']]]))->assertSessionHasErrors();
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['model' => '<script>']))->assertSessionHasErrors('model');
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['model' => str_repeat('a', 151)]))->assertSessionHasErrors('model');
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['base_url' => 'ftp://x.example']))->assertSessionHasErrors('base_url');
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['base_url' => 'javascript:alert(1)']))->assertSessionHasErrors('base_url');
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['priority' => 'abc']))->assertSessionHasErrors('priority');
        $this->actingAs($admin)->post(route('admin.ai-models.store'), array_merge($base, ['name' => 'Модель Oʻzbek']))->assertSessionHasNoErrors();
        $this->assertNotNull(AiModel::where('name', 'Модель Oʻzbek')->first());

        // Old input is kept and validation messages render
        $this->actingAs($admin)->from(route('admin.ai-models.create'))->followingRedirects()
            ->post(route('admin.ai-models.store'), array_merge($base, ['name' => 'Kept name', 'is_active' => 1, 'api_key' => '']))
            ->assertOk()->assertSee('value="Kept name"', false)->assertSee('Paste the API key');
    }

    public function test_duplicate_tasks_are_stored_once(): void
    {
        $this->actingAs($this->admin())->post(route('admin.ai-models.store'), [
            'name' => 'Dup', 'provider' => 'openai', 'model' => 'm', 'base_url' => 'https://api.example.com/v1', 'tasks' => ['chat', 'chat'], 'priority' => 10,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['chat'], AiModel::where('name', 'Dup')->sole()->tasks);
    }

    public function test_update_blank_key_keeps_remove_key_clears_new_key_wins(): void
    {
        $model = $this->model();
        $admin = $this->admin();
        $data = ['name' => 'Groq', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile', 'base_url' => 'https://api.groq.com/openai/v1', 'tasks' => ['chat'], 'priority' => 10, 'is_active' => 1];

        $this->actingAs($admin)->put(route('admin.ai-models.update', $model), $data + ['api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame(self::KEY, $model->fresh()->api_key);

        $this->actingAs($admin)->put(route('admin.ai-models.update', $model), $data + ['remove_key' => 1])->assertSessionHasErrors('api_key');
        $this->assertSame(self::KEY, $model->fresh()->api_key);

        $this->actingAs($admin)->put(route('admin.ai-models.update', $model), $data + ['remove_key' => 1, 'api_key' => 'new-key-123456'])->assertSessionHasNoErrors();
        $this->assertSame('new-key-123456', $model->fresh()->api_key);

        unset($data['is_active']);
        $this->actingAs($admin)->put(route('admin.ai-models.update', $model), $data + ['remove_key' => 1])->assertSessionHasNoErrors();
        $this->assertNull($model->fresh()->api_key);
    }

    public function test_non_admin_is_refused_everywhere(): void
    {
        $model = $this->model();
        $user = User::factory()->create(['is_admin' => false]);
        foreach ([
            ['get', route('admin.ai-models.index')], ['get', route('admin.ai-models.create')], ['get', route('admin.ai-models.edit', $model)],
            ['post', route('admin.ai-models.store')], ['put', route('admin.ai-models.update', $model)], ['delete', route('admin.ai-models.destroy', $model)],
            ['post', route('admin.ai-models.test', $model)], ['post', route('admin.ai-models.resume', $model)], ['post', route('admin.ai-instructions.reset')],
        ] as [$method, $url]) {
            $status = $this->actingAs($user)->{$method}($url)->status();
            $this->assertTrue(in_array($status, [302, 403], true), "{$method} {$url} gave {$status}");
        }
        $this->assertNotNull($model->fresh());
        Http::assertNothingSent();
    }

    // ---- main prompt ----

    public function test_prompt_section_order(): void
    {
        AiInstruction::main()->update(['content' => 'MAIN PROMPT MARKER']);
        AiInstruction::create(['title' => 'R', 'kind' => 'rules', 'channel' => 'all', 'content' => 'RULE MARKER', 'is_active' => true]);
        Cache::flush();

        $p = app(ChatAssistant::class)->promptPreview('uz', 'web');
        $positions = array_map(fn ($s) => strpos($p, $s), ['MAIN PROMPT MARKER', 'REPLY FORMAT', 'STAFF RULES', 'RULE MARKER', "\nWEBSITE DATA\n"]);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertSame(0, strpos($p, 'MAIN PROMPT MARKER'));
    }

    public function test_main_prompt_update_ignores_other_fields_and_rejects_arrays(): void
    {
        $main = AiInstruction::main();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $main), ['content' => ['x']])->assertSessionHasErrors('content');
        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $main), ['content' => str_repeat('я', AiInstruction::MAX_LENGTH + 1)])->assertSessionHasErrors('content');
        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $main), ['content' => 'Yangi matn — новый', 'kind' => 'rules', 'is_active' => 0, 'channel' => 'web'])->assertSessionHasNoErrors();

        $main->refresh();
        $this->assertSame('prompt', $main->kind);
        $this->assertTrue($main->is_active);
        $this->assertSame('all', $main->channel);
        $this->assertStringStartsWith('Yangi matn — новый', app(ChatAssistant::class)->promptPreview('ru', 'telegram'));
    }

    public function test_deleting_main_prompt_shows_error_on_page(): void
    {
        $main = AiInstruction::main();
        $this->actingAs($this->admin())->from(route('admin.ai-instructions.index'))->followingRedirects()
            ->delete(route('admin.ai-instructions.destroy', $main))
            ->assertOk()->assertSee('The main prompt cannot be deleted');
        $this->assertNotNull($main->fresh());
    }

    public function test_cannot_create_second_prompt_document(): void
    {
        $this->actingAs($this->admin())->post(route('admin.ai-instructions.store'), [
            'title' => 'Fake', 'kind' => 'prompt', 'channel' => 'all', 'content' => 'x', 'is_active' => 1,
        ])->assertSessionHasErrors('kind');
    }

    public function test_saving_unchanged_main_prompt_from_browser_keeps_default_label(): void
    {
        $main = AiInstruction::main();
        // Browsers submit textarea line breaks as CRLF.
        $this->actingAs($this->admin())->put(route('admin.ai-instructions.update', $main), ['content' => str_replace("\n", "\r\n", ChatAssistant::DEFAULT_PROMPT)]);

        $this->actingAs($this->admin())->get(route('admin.ai-instructions.index'))->assertSee('default text');
    }
}
