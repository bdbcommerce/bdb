<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\ChatConversation;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Services\AiErrorAlerts;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA edge cases for AI error alerts and the AI rules & info documents.
 */
class AiQaEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyQAKEY0000000000000';

    private const BOT = '123456:QA-BOT-TOKEN-secret';

    private function configureAll(): void
    {
        config([
            'services.gemini.key' => self::KEY,
            'services.telegram.bot_token' => self::BOT,
            'services.telegram.chat_id' => '-100555',
            'services.telegram.alert_chat_id' => '777',
        ]);
    }

    private function ask(string $locale = 'ru', string $text = 'Сколько стоит сканер?')
    {
        return $this->postJson('/chat/message', [
            'locale' => $locale, 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => $text]],
        ]);
    }

    private function alerts()
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '777')
            ->map(fn ($pair) => $pair[0])->values();
    }

    private function geminiOk(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'reply' => 'ok', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
            'knowledge_id' => 0, 'topic' => 'x', 'category' => 'faq', 'answered_ids' => [],
            'type' => 'faq', 'question_uz' => 'q', 'question_ru' => 'q', 'question_en' => 'q',
            'answer_uz' => 'a', 'answer_ru' => 'a', 'answer_en' => 'a', 'keywords' => 'k',
        ])]]]]]])]);
    }

    private function prompts(): array
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'generateContent'))
            ->map(fn (Request $r) => $r['system_instruction']['parts'][0]['text'])->values()->all();
    }

    // ---------------- Alerts ----------------

    public function test_gemini_error_html_is_escaped_in_the_alert(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => [
                'status' => '<b>X</b>', 'message' => '</code><a href="http://evil">click</a> & <script>',
            ]], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertOk();

        $text = $this->alerts()[0]['text'];
        $this->assertStringNotContainsString('<a href', $text);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('<b>X</b>', $text);
        $this->assertStringContainsString('&lt;/code&gt;&lt;a href=&quot;http://evil&quot;&gt;', $text);
        $this->assertStringContainsString('&amp;', $text);
    }

    public function test_bot_token_and_key_never_appear_in_alerts(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => [
                'status' => 'INVALID_ARGUMENT', 'message' => 'bad key '.self::KEY.' and token '.self::BOT,
            ]], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertOk();

        $text = $this->alerts()[0]['text'];
        $this->assertStringNotContainsString(self::KEY, $text);
        $this->assertStringNotContainsString(self::BOT, $text);
        $this->assertStringContainsString('***', $text);
    }

    public function test_429_with_different_retry_texts_is_one_alert(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded. Please retry in 41.2s.']], 429)
                ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded. Please retry in 7.9s.']], 429)
                ->push(['error' => ['code' => 503, 'status' => 'UNAVAILABLE', 'message' => 'overloaded']], 503),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask()->assertOk();
        $this->ask()->assertOk();
        $this->assertCount(1, $this->alerts());

        // A different problem is reported separately.
        $this->ask()->assertOk();
        $this->assertCount(2, $this->alerts());
    }

    public function test_telegram_down_never_breaks_the_customer_reply(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429),
            'api.telegram.org/*' => Http::failedConnection(),
        ]);

        $this->ask()->assertOk()->assertJson(['reply' => __('chat.error', [], 'ru'), 'offer_callback' => true]);
    }

    public function test_telegram_500_never_breaks_the_customer_reply(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429),
            'api.telegram.org/*' => Http::response('<html>Bad gateway</html>', 502),
        ]);

        $this->ask('uz')->assertOk()->assertJson(['reply' => __('chat.error', [], 'uz')]);
    }

    public function test_non_string_error_message_does_not_cause_500(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'X', 'message' => ['nested' => 'array']]], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask('en')->assertOk()->assertJson(['reply' => __('chat.error', [], 'en')]);
    }

    public function test_non_json_error_body_is_reported_and_degrades(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response('<html>502 Bad Gateway</html>', 502),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->ask('en')->assertOk()->assertJson(['reply' => __('chat.error', [], 'en')]);
        $text = $this->alerts()[0]['text'];
        $this->assertStringContainsString('HTTP 502', $text);
        $this->assertStringContainsString('&lt;html&gt;', $text);
    }

    public function test_no_key_and_no_telegram_falls_back_without_any_http(): void
    {
        config(['services.gemini.key' => null, 'services.telegram.bot_token' => null, 'services.telegram.chat_id' => null]);
        Http::fake();

        foreach (['uz', 'ru', 'en'] as $locale) {
            $this->ask($locale)->assertOk()->assertJson(['reply' => __('chat.error', [], $locale), 'offer_callback' => true]);
        }
        Http::assertNothingSent();
    }

    public function test_token_but_no_chat_id_sends_nothing(): void
    {
        config(['services.gemini.key' => null, 'services.telegram.bot_token' => self::BOT, 'services.telegram.chat_id' => null, 'services.telegram.alert_chat_id' => null]);
        Http::fake();

        $this->ask()->assertOk();
        Http::assertNothingSent();
    }

    public function test_other_ai_calls_alert_and_degrade(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['status' => 'A', 'message' => 'a']], 500)
                ->pushFailedConnection()
                ->push(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']])
                ->push(['error' => ['status' => 'B', 'message' => 'b']], 403),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $assistant = app(ChatAssistant::class);
        $entry = KnowledgeEntry::create([
            'type' => 'faq', 'question' => ['en' => 'Q', 'ru' => 'В'], 'answer' => ['en' => 'A'], 'keywords' => '', 'is_active' => true,
        ]);

        $this->assertNull($assistant->draftKnowledge(['How much?']));
        $this->assertSame([], $assistant->matchQuestions($entry, [1 => 'Q?']));
        $this->assertNull($assistant->suggestStaffReply([['role' => 'user', 'content' => 'Hi']], 'ru'));
        $this->assertNotNull($assistant->check());
        $this->assertStringContainsString('HTTP 403 B', $assistant->lastError());

        $texts = $this->alerts()->pluck('text')->implode("\n");
        $this->assertStringContainsString('HTTP 500 A', $texts);
        $this->assertStringContainsString('did not respond', $texts);
        $this->assertStringContainsString('PROHIBITED_CONTENT', $texts);
        $this->assertStringContainsString('HTTP 403 B', $texts);
    }

    public function test_last_error_resets_after_success(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'x']], 500)
                ->push(['candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $assistant = app(ChatAssistant::class);
        $this->assertNotNull($assistant->check());
        $this->assertNull($assistant->check());
        $this->assertNull($assistant->lastError());
    }

    public function test_ai_check_never_prints_secrets_even_if_gemini_echoes_them(): void
    {
        $this->configureAll();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => [
                'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid: '.self::KEY,
            ]], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('ai:check')
            ->doesntExpectOutputToContain(self::KEY)
            ->doesntExpectOutputToContain(self::BOT)
            ->assertFailed();
    }

    public function test_ai_check_without_key_and_alert_option(): void
    {
        $this->configureAll();
        config(['services.gemini.key' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->artisan('ai:check --alert')
            ->expectsOutputToContain('not set')
            ->expectsOutputToContain('Add the key in Admin → AI settings')
            ->expectsOutputToContain('Telegram chat 777')
            ->doesntExpectOutputToContain(self::BOT)
            ->assertFailed();

        $texts = $this->alerts()->pluck('text')->implode("\n");
        $this->assertStringContainsString('Test alert from php artisan ai:check', $texts);
        // ai:check tests models directly and reports on the console; it doesn't send failure alerts.
        $this->assertStringNotContainsString('GEMINI_API_KEY is not set', $texts);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage'));
    }

    public function test_ai_check_shows_alerts_off_without_telegram(): void
    {
        config(['services.gemini.key' => null, 'services.telegram.bot_token' => null]);
        Http::fake();
        $this->artisan('ai:check')->expectsOutputToContain('off')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_alert_message_is_short_and_escaped_for_long_details(): void
    {
        $this->configureAll();
        $msg = app(AiErrorAlerts::class)->message('R', str_repeat('Ж<', 2000), null);
        $this->assertLessThan(4096, mb_strlen($msg), 'Telegram limit is 4096 characters');
        $this->assertStringNotContainsString('Ж<', $msg);
    }

    // ---------------- AI instructions ----------------

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admin_users_are_refused(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.ai-instructions.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->get(route('admin.ai-instructions.prompt'))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->post(route('admin.ai-instructions.store'), ['title' => 'x', 'kind' => 'rules', 'channel' => 'all', 'content' => 'x'])
            ->assertRedirect(route('admin.login'));
        $doc = AiInstruction::create(['title' => 't', 'kind' => 'rules', 'channel' => 'all', 'content' => 'c']);
        $this->actingAs($user)->delete(route('admin.ai-instructions.destroy', $doc))->assertRedirect(route('admin.login'));
        $this->actingAs($user)->put(route('admin.ai-instructions.update', $doc), ['title' => 'h', 'kind' => 'rules', 'channel' => 'all', 'content' => 'h'])
            ->assertRedirect(route('admin.login'));
        $this->assertSame('t', $doc->fresh()->title);
        $this->assertSame(1, AiInstruction::where('kind', '!=', AiInstruction::MAIN)->count());
    }

    public function test_admin_pages_render_and_missing_record_is_404(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.ai-instructions.index'))->assertOk()->assertSee('No documents yet');
        $this->actingAs($admin)->get(route('admin.ai-instructions.create'))->assertOk();
        $this->actingAs($admin)->get('/admin/ai-instructions/999/edit')->assertNotFound();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.ai-instructions.index'), false);
    }

    public function test_xss_in_title_and_content_is_escaped_everywhere(): void
    {
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $doc = AiInstruction::create(['title' => $payload, 'kind' => 'info', 'channel' => 'all', 'content' => "</textarea>{$payload}", 'is_active' => true]);
        $admin = $this->admin();

        foreach ([
            route('admin.ai-instructions.index'),
            route('admin.ai-instructions.edit', $doc),
            route('admin.ai-instructions.prompt', ['channel' => 'web', 'locale' => 'en']),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertDontSee('<script>alert(1)</script>', false)
                ->assertDontSee('<img src=x', false)
                ->assertDontSee('</textarea><script>', false);
        }
    }

    public function test_array_inputs_and_bad_values_are_validation_errors_not_500(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [
            'title' => ['a'], 'kind' => ['rules'], 'channel' => ['all'], 'content' => ['x'], 'sort_order' => ['1'],
        ])->assertRedirect()->assertSessionHasErrors(['title', 'kind', 'channel', 'content', 'sort_order']);

        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [
            'title' => str_repeat('a', 151), 'kind' => 'RULES', 'channel' => 'All', 'content' => '', 'sort_order' => 70000,
        ])->assertSessionHasErrors(['title', 'kind', 'channel', 'content', 'sort_order']);

        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [
            'title' => 'x', 'kind' => 'rules', 'channel' => 'all', 'content' => 'x', 'sort_order' => -1,
        ])->assertSessionHasErrors(['sort_order']);

        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [])
            ->assertSessionHasErrors(['title', 'kind', 'channel', 'content']);

        $this->assertSame(0, AiInstruction::where('kind', '!=', AiInstruction::MAIN)->count());
    }

    public function test_max_length_boundary_and_cyrillic_and_uzbek_text(): void
    {
        $admin = $this->admin();
        $content = str_repeat('Ж', AiInstruction::MAX_LENGTH);
        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [
            'title' => 'Oʻzbekcha qoidalar — Правила', 'kind' => 'rules', 'channel' => 'all', 'content' => $content, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $doc = AiInstruction::where('kind', '!=', AiInstruction::MAIN)->sole();
        $this->assertSame(AiInstruction::MAX_LENGTH, mb_strlen($doc->content));
        $this->assertSame('Oʻzbekcha qoidalar — Правила', $doc->title);
        $this->actingAs($admin)->get(route('admin.ai-instructions.index'))->assertSee('Oʻzbekcha qoidalar — Правила')->assertSee('20,000 characters');
    }

    public function test_old_input_is_kept_after_validation_error(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.ai-instructions.create'))->post(route('admin.ai-instructions.store'), [
            'title' => '', 'kind' => 'info', 'channel' => 'telegram', 'content' => 'Kept content', 'sort_order' => 5,
        ])->assertRedirect(route('admin.ai-instructions.create'));

        $this->actingAs($admin)->get(route('admin.ai-instructions.create'))->assertOk()
            ->assertSee('Kept content')
            ->assertSee('value="info" class="mt-0.5" checked', false)
            ->assertSee('<option value="telegram" selected', false)
            ->assertSee('value="5"', false);
    }

    public function test_unchecked_active_is_kept_after_validation_error(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.ai-instructions.create'))->post(route('admin.ai-instructions.store'), [
            'title' => '', 'kind' => 'rules', 'channel' => 'all', 'content' => 'x',
        ]);

        $html = $this->actingAs($admin)->get(route('admin.ai-instructions.create'))->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="is_active" value="1" checked/', $html, 'Unchecked "Active" should stay unchecked');
    }

    public function test_form_still_works_without_easymde(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.ai-instructions.create'))->getContent();
        // The editor is progressive enhancement: the textarea is a real form field and is not "required"
        // (a hidden required textarea would block submission once EasyMDE replaces it).
        $this->assertMatchesRegularExpression('/<textarea id="content" name="content"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]*\brequired\b/', $html);
        $this->assertStringContainsString('if (window.EasyMDE)', $html);
        $this->assertStringContainsString('<label for="content"', $html);
        $this->assertStringContainsString('<label for="title"', $html);
    }

    public function test_ordering_by_sort_order_then_id(): void
    {
        AiInstruction::create(['title' => 'Third', 'kind' => 'rules', 'channel' => 'all', 'content' => 'R3', 'sort_order' => 5]);
        AiInstruction::create(['title' => 'First', 'kind' => 'rules', 'channel' => 'web', 'content' => 'R1', 'sort_order' => 1]);
        AiInstruction::create(['title' => 'Second', 'kind' => 'rules', 'channel' => 'all', 'content' => 'R2', 'sort_order' => 1]);
        AiInstruction::create(['title' => 'Info', 'kind' => 'info', 'channel' => 'all', 'content' => 'I1', 'sort_order' => 0]);

        $prompt = app(ChatAssistant::class)->promptPreview('en', 'web');
        $this->assertTrue(strpos($prompt, 'R1') < strpos($prompt, 'R2') && strpos($prompt, 'R2') < strpos($prompt, 'R3'));
        // Info goes after the website data, rules before it.
        $this->assertGreaterThan(strrpos($prompt, "\nWEBSITE DATA\n"), strpos($prompt, 'I1'));
        $this->assertLessThan(strrpos($prompt, "\nWEBSITE DATA\n"), strpos($prompt, 'R3'));
    }

    public function test_cache_flushed_on_update_deactivate_and_delete(): void
    {
        $assistant = app(ChatAssistant::class);
        $doc = AiInstruction::create(['title' => 'T', 'kind' => 'rules', 'channel' => 'telegram', 'content' => 'V1', 'is_active' => true]);
        $this->assertStringContainsString('V1', $assistant->promptPreview('ru', 'telegram'));

        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $doc), [
            'title' => 'T', 'kind' => 'rules', 'channel' => 'telegram', 'content' => 'V2', 'is_active' => 1,
        ])->assertRedirect();
        $this->assertStringContainsString('V2', $assistant->promptPreview('ru', 'telegram'));
        $this->assertStringNotContainsString('V1', $assistant->promptPreview('ru', 'telegram'));

        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $doc), [
            'title' => 'T', 'kind' => 'rules', 'channel' => 'telegram', 'content' => 'V2',
        ]);
        $this->assertStringNotContainsString('V2', $assistant->promptPreview('ru', 'telegram'));

        $doc->refresh()->update(['is_active' => true]);
        $this->assertStringContainsString('V2', $assistant->promptPreview('ru', 'telegram'));
        $this->actingAs($admin)->delete(route('admin.ai-instructions.destroy', $doc));
        $this->assertStringNotContainsString('V2', $assistant->promptPreview('ru', 'telegram'));
        $this->assertStringNotContainsString('STAFF RULES', $assistant->promptPreview('ru', 'telegram'));
    }

    public function test_preview_renders_for_all_locales_and_channels(): void
    {
        AiInstruction::create(['title' => 'Info', 'kind' => 'info', 'channel' => 'all', 'content' => 'INFO-X']);
        $admin = $this->admin();
        foreach (['uz' => 'Uzbek (Latin script)', 'ru' => 'Russian', 'en' => 'English'] as $locale => $language) {
            foreach (['web', 'telegram'] as $channel) {
                $this->actingAs($admin)->get(route('admin.ai-instructions.prompt', compact('locale', 'channel')))
                    ->assertOk()->assertSee($language)->assertSee('INFO-X')->assertSee('aria-current="true"', false);
            }
        }
        $this->actingAs($admin)->get(route('admin.ai-instructions.prompt', ['channel' => ['x'], 'locale' => ['y']]))->assertOk();
    }

    public function test_staff_reply_and_draft_use_the_right_documents(): void
    {
        AiInstruction::create(['title' => 'TG', 'kind' => 'rules', 'channel' => 'telegram', 'content' => 'RULE-TG']);
        AiInstruction::create(['title' => 'Web', 'kind' => 'rules', 'channel' => 'web', 'content' => 'RULE-WEB']);
        AiInstruction::create(['title' => 'TGinfo', 'kind' => 'info', 'channel' => 'telegram', 'content' => 'INFO-TG']);
        AiInstruction::create(['title' => 'Webinfo', 'kind' => 'info', 'channel' => 'web', 'content' => 'INFO-WEB']);
        config(['services.gemini.key' => 'k']);
        $this->geminiOk();

        $assistant = app(ChatAssistant::class);
        $assistant->suggestStaffReply([['role' => 'user', 'content' => 'Salom']], 'uz');
        $assistant->draftKnowledge(['Narxi qancha?']);
        [$staff, $draft] = $this->prompts();

        $this->assertStringContainsString('RULE-TG', $staff);
        $this->assertStringContainsString('INFO-TG', $staff);
        $this->assertStringNotContainsString('RULE-WEB', $staff);
        $this->assertStringNotContainsString('INFO-WEB', $staff);

        $this->assertStringContainsString('INFO-WEB', $draft);
        $this->assertStringNotContainsString('RULE-', $draft);
    }

    public function test_prompt_size_with_many_large_documents(): void
    {
        foreach (range(1, 10) as $i) {
            AiInstruction::create(['title' => "Doc {$i}", 'kind' => $i % 2 ? 'rules' : 'info', 'channel' => 'all', 'content' => str_repeat('x', AiInstruction::MAX_LENGTH)]);
        }
        $prompt = app(ChatAssistant::class)->promptPreview('ru', 'web');
        // Rules and info are each capped at MAX_TOTAL, so the prompt stays bounded.
        $this->assertLessThan(2 * AiInstruction::MAX_TOTAL + 20000, mb_strlen($prompt));
        $this->assertStringContainsString('[… cut: the documents are longer than the limit]', $prompt);

        $this->actingAs($this->admin())->get(route('admin.ai-instructions.prompt'))->assertOk();
    }

    public function test_telegram_bot_staff_suggest_endpoint_still_works_without_key(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();
        $c = ChatConversation::create(['uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [['role' => 'user', 'content' => 'Привет', 'at' => now()->toIso8601String()]]]);

        $route = collect(app('router')->getRoutes())->first(fn ($r) => str_contains((string) $r->getName(), 'suggest'));
        if (! $route) {
            $this->markTestSkipped('no suggest route');
        }
        $this->actingAs($this->admin())->postJson(route($route->getName(), $c))->assertStatus(422);
        Http::assertNothingSent();
    }
}
