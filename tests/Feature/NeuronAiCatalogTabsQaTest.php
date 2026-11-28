<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\ChatConversation;
use App\Models\ExpertQuestion;
use App\Models\OnecItem;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\VoiceTranscription;
use App\Services\Ai\VoiceTranscriber;
use App\Services\SalesAssistant\ExpertDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** QA: NeuronAI voice transcription edge cases, and the Product catalog tabs (items from 1C / price list). */
class NeuronAiCatalogTabsQaTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'nai_secret_key_987654';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.neuronai.key' => self::KEY, 'services.neuronai.url' => 'https://my.neuronai.uz/api/v1', 'services.neuronai.language' => 'auto',
            'services.groq.key' => null,
            'services.telegram.bot_token' => 'BOT', 'services.telegram.chat_id' => '-100123', 'services.telegram.webhook_secret' => 'secret_123',
            'services.telegram.voice' => 'transcribe', 'services.gemini.key' => 'test-key',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function alerts(): string
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($pair) => $pair[0]['text'])->implode("\n");
    }

    private function fakeNeuron($response): void
    {
        Http::swap(new Factory); // a later Http::fake() does not replace an earlier stub
        Http::fake([
            'my.neuronai.uz/*' => $response,
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    // ---- NeuronAI: response shapes ----

    public function test_odd_success_shapes_never_crash(): void
    {
        $voice = app(VoiceTranscriber::class);

        $this->fakeNeuron(Http::response(['data' => ['text' => ['a', 'b'], 'duration_seconds' => '4.5']]));
        $this->assertSame(['text' => null, 'seconds' => 4.5], $voice->transcribe('OggS'));

        // No "data" at all is a failure (admins alerted), not silence.
        $this->fakeNeuron(Http::response(['request_id' => 'r1']));
        $this->assertNull($voice->transcribe('OggS'));

        $this->fakeNeuron(Http::response(['data' => ['text' => "  Salom \n  dunyo  ", 'duration_seconds' => null]]));
        $this->assertSame(['text' => 'Salom dunyo', 'seconds' => null], $voice->transcribe('OggS'));

        $this->fakeNeuron(Http::response(['data' => 'oops']));
        $this->assertNull($voice->transcribe('OggS')); // the alert itself: NeuronAiVoiceTest
    }

    public function test_error_bodies_are_reported_without_the_key(): void
    {
        Log::spy();
        // An HTML error page that echoes the key.
        $this->fakeNeuron(Http::response('<html><body>Bad gateway for Bearer '.self::KEY.str_repeat(' x', 2000).'</body></html>', 502, ['Content-Type' => 'text/html']));
        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS'));
        $alert = $this->alerts();
        $this->assertStringContainsString('NeuronAI HTTP 502', $alert);
        $this->assertStringNotContainsString(self::KEY, $alert);
        $this->assertLessThan(4096, mb_strlen($alert));
        Log::shouldHaveReceived('warning')->withArgs(fn ($reason, $context = []) => ! str_contains($reason.json_encode($context), self::KEY));
    }

    public function test_each_documented_error_status_returns_null_and_alerts(): void
    {
        foreach ([401 => 'invalid_api_key', 403 => 'permission_denied', 413 => 'file_too_large', 415 => 'unsupported_media_type', 422 => 'validation_error', 429 => 'rate_limit_exceeded', 500 => 'internal_error'] as $status => $code) {
            Cache::flush();
            $this->fakeNeuron(Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'Nope', 'param' => null]], $status));
            $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS'), (string) $status);
            $this->assertStringContainsString("NeuronAI HTTP {$status} ({$code})", $this->alerts());
        }
    }

    public function test_a_connection_error_is_reported_without_the_key(): void
    {
        Http::fake([
            'my.neuronai.uz/*' => fn () => throw new ConnectionException('cURL error 28 for key '.self::KEY),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS'));
        $this->assertStringContainsString('NeuronAI did not respond', $this->alerts());
        $this->assertStringNotContainsString(self::KEY, $this->alerts());
    }

    private function voiceUpdate(array $audio, string $kind = 'voice', int $id = 101): array
    {
        return ['update_id' => $id, 'message' => [
            'message_id' => $id, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(),
            $kind => $audio,
        ]];
    }

    private function botFakes($neuron, string $path = 'voice/file_1.oga'): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        Http::fake([
            'my.neuronai.uz/*' => $neuron,
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'A manager will give the price.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true])]]]]]]),
            // A real one-page Ogg Opus file of 5 s (granule 240000 at 48 kHz): only measurable voice goes to NeuronAI.
            'api.telegram.org/file/*' => Http::response('OggS'."\0\x02".pack('P', 5 * 48000).str_repeat("\0", 12).chr(1).chr(19).'OpusHead'.str_repeat("\0", 11), 200, ['Content-Type' => 'audio/ogg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => $path, 'message_id' => 9]]),
        ]);
    }

    public function test_the_daily_seconds_budget_counts_neuronai_duration(): void
    {
        $this->botFakes(Http::response(['data' => ['text' => 'Сколько стоит принтер этикеток Zebra сегодня', 'duration_seconds' => 41.2]]));
        $this->postJson('/telegram/webhook', $this->voiceUpdate(['file_id' => 'f1', 'duration' => 5, 'mime_type' => 'audio/ogg', 'file_size' => 4000]), ['X-Telegram-Bot-Api-Secret-Token' => 'secret_123'])->assertOk();

        $this->assertSame(42, (int) Cache::get('telegram-voice:seconds:'.now()->toDateString()));
        $this->assertTrue(ChatConversation::sole()->messages[0]['transcribed']);
    }

    // ---- AI settings page: four states ----

    public function test_ai_settings_voice_block_in_all_four_states(): void
    {
        $admin = $this->admin();
        $page = fn () => preg_replace('/\s+/', ' ', $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()->assertDontSee(self::KEY)->getContent());

        // NeuronAI on
        $html = $page();
        $this->assertStringContainsString('transcribed by NeuronAI (language: auto)', $html);
        $this->assertStringNotContainsString('Groq Whisper (', $html);

        // Groq on
        config(['services.neuronai.key' => null, 'services.groq.key' => 'gsk_secret_key_555']);
        $html = $page();
        $this->assertStringContainsString('transcribed by Groq Whisper (whisper-large-v3)', $html);
        $this->assertStringContainsString('GROQ_API_KEY in .env', $html);
        $this->assertStringContainsString('Set NEURONAI_API_KEY in .env to use NeuronAI instead', $html);
        $this->assertStringNotContainsString('gsk_secret_key_555', $html);

        // Off
        config(['services.groq.key' => null]);
        $html = $page();
        $this->assertStringContainsString('off — set NEURONAI_API_KEY in .env', $html);
        $this->assertStringContainsString('only saved in the chat', $html);

        // Forward mode
        config(['services.telegram.voice' => 'forward']);
        $html = $page();
        $this->assertStringContainsString('forwarded to the sales chat', $html);
        $this->assertStringContainsString('transcribed by NeuronAI (needs NEURONAI_API_KEY)', $html);
        // Only a Groq key: switching to transcribe would use Groq, and the hint says so.
        config(['services.groq.key' => 'gsk_secret_key_555']);
        $this->assertStringContainsString('transcribed by Groq Whisper (NeuronAI once NEURONAI_API_KEY is set)', $page());
        config(['services.neuronai.key' => self::KEY]);
        $html = $page();
        $this->assertStringContainsString('transcribed by NeuronAI and answered', $html);

    }

    // ---- Product catalog tabs ----

    private function item(): OnecItem
    {
        return OnecItem::create(['onec_id' => 'g1', 'code' => 7, 'name' => 'Xprinter XP-420B']);
    }

    public function test_guests_and_non_admins_cannot_open_either_list(): void
    {
        $this->item();
        foreach ([route('admin.catalog.index'), route('admin.catalog.index', ['list' => 'prices']), route('admin.products.index')] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $user = User::factory()->create(['is_admin' => false]);
        foreach ([route('admin.catalog.index'), route('admin.products.index')] as $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            $this->assertContains($status, [302, 403], $url);
            $this->assertNotSame(route('admin.products.index'), $this->actingAs($user)->get($url)->headers->get('Location'));
        }
    }

    public function test_saving_in_push_mode_shows_the_message_and_the_token_once_with_1c_items(): void
    {
        $this->item();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.catalog.update'), ['source' => 'push', 'max_age_hours' => 24])->assertRedirect(route('admin.catalog.index'));
        $html = $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Catalog settings saved.')->assertSee('copy it into 1C now')->getContent();
        $this->assertMatchesRegularExpression('/<code[^>]*>[A-Za-z0-9_\-|]{20,}<\/code>/', $html);
        $this->assertNotNull(CatalogSetting::current()->token_hash);

        // Reload: the token is gone and the bare address goes to the items.
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertRedirect(route('admin.products.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index', ['list' => 'prices']))->assertOk()->assertDontSee('copy it into 1C now');
    }

    public function test_pull_errors_and_success_are_shown_with_1c_items(): void
    {
        $this->item();
        $admin = $this->admin();

        // Push mode: pull refused.
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertRedirect(route('admin.catalog.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Switch the source to pull first.');

        CatalogSetting::current()->update(['source' => 'pull', 'pull_url' => 'https://1c.example.uz/hs/catalog', 'pull_username' => 'bdb', 'pull_password' => 'password-123']);
        Http::fake(['1c.example.uz/*' => Http::response('nope', 500), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertRedirect(route('admin.catalog.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Not loaded:');

        Http::swap(new Factory);
        Http::fake(['1c.example.uz/*' => Http::response(['products' => [[
            'sku' => 'XP-1', 'brand' => 'Xprinter', 'model' => 'XP-420B', 'category' => array_key_first(CatalogProduct::CATEGORIES), 'name' => ['ru' => 'Принтер этикеток'],
            'prices' => ['end_user' => 1000000, 'dealer' => 900000, 'partner' => 800000], 'stock' => ['status' => 'in_stock', 'qty' => 3], 'active' => true,
        ]]])]);
        $this->actingAs($admin)->post(route('admin.catalog.pull'))->assertRedirect(route('admin.catalog.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Loaded: 1 products.');
    }

    public function test_validation_errors_and_old_input_are_shown_with_1c_items(): void
    {
        $this->item();
        $admin = $this->admin();

        foreach ([route('admin.catalog.index'), route('admin.catalog.index', ['list' => 'prices'])] as $from) {
            $this->actingAs($admin)->from($from)->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'http://1c.example.uz/x', 'pull_username' => 'bdb-user', 'max_age_hours' => 0])
                ->assertRedirect($from);
            $this->actingAs($admin)->get($from)->assertOk()
                ->assertSee('The 1C address must start with https://.')->assertSee('value="http://1c.example.uz/x"', false)->assertSee('value="bdb-user"', false);
        }

        // Pull without any password: the controller's own error.
        $this->actingAs($admin)->from(route('admin.catalog.index'))->put(route('admin.catalog.update'), ['source' => 'pull', 'pull_url' => 'https://1c.example.uz/x', 'pull_username' => 'bdb-user', 'max_age_hours' => 24])
            ->assertRedirect(route('admin.catalog.index'));
        $this->actingAs($admin)->get(route('admin.catalog.index'))->assertOk()->assertSee('Enter the password of the 1C user.')->assertSee('value="https://1c.example.uz/x"', false);
    }

    public function test_price_list_pagination_and_filters_never_lead_to_the_redirect(): void
    {
        $this->item();
        $admin = $this->admin();
        $category = array_key_first(CatalogProduct::CATEGORIES);
        foreach (range(1, 60) as $i) {
            CatalogProduct::create(['sku' => "SKU-{$i}", 'brand' => 'Zebra', 'model' => sprintf('ZD%03d', $i), 'category' => $category, 'name' => 'Printer', 'prices' => ['end_user' => 1000], 'stock_status' => 'in_stock', 'is_active' => $i > 55 ? false : true, 'in_feed' => true]);
        }

        $html = $this->actingAs($admin)->get(route('admin.catalog.index', ['list' => 'prices']))->assertOk()->getContent();
        // Every catalog link in the page body (the sidebar item is the only bare one).
        preg_match_all('/href="([^"]*\/admin\/catalog[^"]*)"/', (string) preg_replace('/<nav class="flex-1.*?<\/nav>/s', '', $html), $m);
        $links = array_unique(array_map('html_entity_decode', $m[1]));
        $this->assertNotEmpty(array_filter($links, fn ($l) => str_contains($l, 'page=2')));
        foreach ($links as $link) {
            $this->assertNotSame('', (string) parse_url($link, PHP_URL_QUERY), "bare catalog link on the price list: {$link}");
            $this->actingAs($admin)->get($link)->assertOk();
        }

        // Filter form keeps the tab; hidden list and its "back" link too.
        $this->assertStringContainsString('<input type="hidden" name="list" value="prices">', $html);
        $this->actingAs($admin)->get(route('admin.catalog.index', ['list' => 'prices', 'q' => 'ZD001', 'category' => $category]))->assertOk()->assertSee('ZD001')->assertDontSee('ZD002');
        $hidden = $this->actingAs($admin)->get(route('admin.catalog.index', ['hidden' => 1]))->assertOk()->assertSee('Hidden products')->getContent();
        $this->assertStringContainsString(e(route('admin.catalog.index', ['list' => 'prices'])), $hidden);
        // Odd query values
        $this->actingAs($admin)->get('/admin/catalog?list[]=x&q[]=y&page=abc')->assertOk();
        $this->actingAs($admin)->get('/admin/catalog?page=999')->assertOk();
    }

    public function test_sidebar_highlights_exactly_one_item_and_tabs_mark_the_current_list(): void
    {
        $admin = $this->admin();
        $main = function (string $url) use ($admin) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            preg_match('/<nav class="flex-1[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
            preg_match('/<nav[^>]*aria-label="Product catalog".*?<\/nav>/s', $html, $tabs);

            return [$nav[0] ?? '', $tabs[0] ?? '', $html];
        };

        // No 1C items: the bare address is the price list.
        [$nav, $tabs] = $main(route('admin.catalog.index'));
        $this->assertSame(1, substr_count($nav, 'aria-current="page"'));
        $this->assertSame(1, substr_count($tabs, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*Price list/s', $tabs);

        $item = $this->item();
        foreach ([route('admin.products.index'), route('admin.products.index', ['q' => 'Xprinter', 'info' => 'empty']), route('admin.products.edit', $item), route('admin.catalog.index', ['list' => 'prices'])] as $url) {
            [$nav, $tabs, $html] = $main($url);
            $this->assertSame(1, substr_count($nav, 'aria-current="page"'), $url);
            $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>.*?Product catalog/s', $nav, $url);
            $this->assertStringContainsString('Product catalog', $html);
        }
    }

    public function test_tab_bar_wraps_on_narrow_screens_and_uses_no_colour(): void
    {
        $view = file_get_contents(resource_path('views/admin/partials/catalog-tabs.blade.php'));
        $this->assertStringContainsString('flex flex-wrap', $view);
        $this->assertDoesNotMatchRegularExpression('/\b(?:w|min-w)-\[?\d|whitespace-nowrap|(?:text|bg|border)-(?:red|blue|green|indigo|emerald|amber|sky|violet)-/', $view);
        $this->assertStringNotContainsString('<svg', $view);
    }

    public function test_companies_page_links_to_the_catalog_instead_of_loading_items(): void
    {
        $this->item();
        $this->actingAs($this->admin())->get(route('admin.clients.index'))->assertOk()
            ->assertSee('Load companies from 1C')->assertDontSee('Load items from 1C')
            ->assertDontSee(route('admin.companies.import-items'), false)
            ->assertSee(route('admin.products.index'), false)->assertSee('Items from 1C (1)');
    }

    public function test_item_search_scope_handles_wildcards_and_cyrillic(): void
    {
        OnecItem::create(['onec_id' => 'a', 'code' => 100, 'name' => 'Этикетка 58x40 100%']);
        OnecItem::create(['onec_id' => 'b', 'code' => 200, 'name' => 'Ribbon wax_resin', 'barcode' => '4780001']);
        OnecItem::create(['onec_id' => 'c', 'code' => 300, 'name' => 'Zebra ZD421', 'keywords' => 'принтер']);

        $names = fn (string $q) => OnecItem::search($q)->orderBy('code')->pluck('code')->all();
        $this->assertSame([100], $names('этикетка'));
        $this->assertSame([100], $names('ЭТИКЕТКА'));
        $this->assertSame([100], $names('100%'));
        $this->assertSame([200], $names('wax_resin'));
        $this->assertSame([], $names('wax-resin'));
        $this->assertSame([], $names('%'.'zzz'));
        $this->assertSame([300], $names('Принтер'));
        $this->assertSame([200], $names('200'));
        $this->assertSame([200], $names('4780001'));
        $this->assertCount(3, $names(''));
        $this->assertSame([], $names("' OR 1=1 --"));
    }

    // ---- voice_transcriptions: every voice sent for transcription is kept ----

    private static function oggBody(float $seconds): string
    {
        return 'OggS'."\0\x02".pack('P', (int) ($seconds * 48000)).str_repeat("\0", 12).chr(1).chr(19).'OpusHead'.str_repeat("\0", 11);
    }

    private function neuronOk(string $text = 'Salom dunyo', float $seconds = 2.5): array
    {
        return ['data' => ['text' => $text, 'language' => 'uz', 'duration_seconds' => $seconds, 'cost' => 2.5], 'request_id' => 'req_1'];
    }

    /** One fake for the whole bot flow; the file body is chosen per test. */
    private function voiceFlowFakes(array $transcriber, string $body): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        Http::fake($transcriber + [
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'A manager will give the price.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true])]]]]]]),
            'api.telegram.org/file/*' => Http::response($body, 200, ['Content-Type' => 'audio/ogg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/file_1.oga', 'message_id' => 9]]),
        ]);
    }

    private function sendVoice(array $audio, int $id, string $kind = 'voice'): void
    {
        $this->postJson('/telegram/webhook', $this->voiceUpdate($audio, $kind, $id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret_123'])->assertOk();
    }

    public function test_voice_transcriptions_migration_goes_down_and_up(): void
    {
        $migration = require database_path('migrations/2026_11_28_110000_create_voice_transcriptions_table.php');
        $this->assertTrue(Schema::hasTable('voice_transcriptions'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('voice_transcriptions'));
        $migration->down(); // a second rollback is harmless

        $migration->up();
        $this->assertTrue(Schema::hasColumns('voice_transcriptions', ['id', 'file_id', 'file_unique_id', 'telegram_id', 'message_id', 'source', 'provider', 'status', 'text', 'language', 'seconds', 'cost', 'request_id', 'error', 'created_at', 'updated_at']));

        $row = VoiceTranscription::create(['file_id' => 'f', 'provider' => 'neuronai', 'status' => 'ok'])->fresh();
        $this->assertSame(['bot', null, null, null], [$row->source, $row->text, $row->seconds, $row->message_id]);
    }

    public function test_long_and_odd_values_are_cut_or_dropped_never_thrown(): void
    {
        $voice = app(VoiceTranscriber::class);

        $this->fakeNeuron(Http::response(['data' => ['text' => str_repeat('ў', 5000), 'language' => str_repeat('uzbek', 10), 'duration_seconds' => '4.5', 'cost' => '3.20'], 'request_id' => str_repeat('r', 200)]));
        $result = $voice->transcribeVoice(['file_id' => str_repeat('A', 600), 'file_unique_id' => ['x'], 'telegram_id' => 5551234567, 'message_id' => '77', 'source' => str_repeat('s', 50)], 'OggS');
        $this->assertSame(3000, mb_strlen($result['text']));
        $row = VoiceTranscription::sole();
        $this->assertSame(
            [255, null, '5551234567', 77, 20, 3000, 10, 4.5, 3.2, 64, 'ok', null],
            [mb_strlen($row->file_id), $row->file_unique_id, $row->telegram_id, $row->message_id, mb_strlen($row->source), mb_strlen($row->text), mb_strlen($row->language), $row->seconds, $row->cost, mb_strlen($row->request_id), $row->status, $row->error],
        );
        $row->delete();

        // Arrays / words where numbers and strings are expected.
        $this->fakeNeuron(Http::response(['data' => ['text' => 'Salom', 'language' => ['uz'], 'duration_seconds' => 'long', 'cost' => 'free'], 'request_id' => ['r']]));
        $this->assertSame(['text' => 'Salom', 'seconds' => null], $voice->transcribeVoice(['file_id' => 'f2', 'file_unique_id' => 12345, 'telegram_id' => ['x'], 'message_id' => ['a'], 'source' => ['x']], 'OggS'));
        $row = VoiceTranscription::sole();
        $this->assertSame(['12345', null, null, 'bot', null, null, null, null], [$row->file_unique_id, $row->telegram_id, $row->message_id, $row->source, $row->language, $row->seconds, $row->cost, $row->request_id]);
        $row->delete();

        // A numeric request_id, a huge message_id, a negative one.
        $this->fakeNeuron(Http::response(['data' => ['text' => 'Salom', 'language' => 'uz', 'duration_seconds' => 1, 'cost' => 1], 'request_id' => 987654321]));
        $this->assertNotNull($voice->transcribeVoice(['file_id' => 'f3', 'message_id' => '99999999999999999999999999', 'source' => ''], 'OggS'));
        $this->assertNotNull($voice->transcribeVoice(['file_id' => 'f4', 'message_id' => -5, 'source' => null], 'OggS'));
        $this->assertSame(['987654321', 'bot'], [VoiceTranscription::where('file_id', 'f3')->sole()->request_id, VoiceTranscription::where('file_id', 'f4')->sole()->source]);
        VoiceTranscription::query()->delete();

        // No usable file ID: nothing kept, the text is still returned.
        foreach ([[], ['file_id' => ''], ['file_id' => 5], ['file_id' => ['a']], ['file_id' => null]] as $meta) {
            $this->assertSame('Salom', $voice->transcribeVoice($meta, 'OggS')['text']);
        }
        $this->assertSame(0, VoiceTranscription::count());
    }

    public function test_status_and_error_for_every_outcome_and_no_key_in_the_table(): void
    {
        $voice = app(VoiceTranscriber::class);
        $run = function (string $id, $response) use ($voice) {
            Cache::flush();
            $this->fakeNeuron($response);

            return $voice->transcribeVoice(['file_id' => $id], 'OggS');
        };

        $this->assertNotNull($run('ok', Http::response($this->neuronOk())));
        $this->assertNotNull($run('silence', Http::response($this->neuronOk(''))));
        $this->assertNull($run('http', Http::response('<html>Bad gateway for Bearer '.self::KEY.'</html>', 502)));
        $this->assertNull($run('json', Http::response(['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key '.self::KEY]], 401)));
        // The provider's error code is shown when it is a plain word — also when that word is the key itself.
        $this->assertNull($run('code', Http::response(['error' => ['code' => self::KEY, 'message' => 'x']], 401)));
        $this->assertNull($run('conn', fn () => throw new ConnectionException('cURL error 28 for key '.self::KEY)));
        $this->assertNull($run('nodata', Http::response(['error' => ['message' => 'No credits for '.self::KEY]])));
        $this->assertNull($run('html200', Http::response('<html>Login '.self::KEY.'</html>')));
        config(['services.neuronai.key' => null, 'services.groq.key' => null]);
        $this->assertNull($run('nokey', Http::response($this->neuronOk())));

        $rows = VoiceTranscription::orderBy('id')->get();
        $this->assertSame([
            ['ok', 'neuronai', 'ok', null, 'Salom dunyo'],
            ['silence', 'neuronai', 'empty', null, null],
            ['http', 'neuronai', 'failed', 'NeuronAI HTTP 502', null],
            ['json', 'neuronai', 'failed', 'NeuronAI HTTP 401 (invalid_api_key)', null],
            ['conn', 'neuronai', 'failed', 'NeuronAI did not respond', null],
            ['nodata', 'neuronai', 'failed', 'NeuronAI sent an answer without data', null],
            ['html200', 'neuronai', 'failed', 'NeuronAI sent an answer without data', null],
            ['nokey', 'groq', 'failed', 'no API key', null],
        ], $rows->where('file_id', '!=', 'code')->map(fn ($r) => [$r->file_id, $r->provider, $r->status, $r->error, $r->text])->values()->all());

        $code = $rows->firstWhere('file_id', 'code');
        $this->assertSame('failed', $code->status);
        $this->assertStringStartsWith('NeuronAI HTTP 401', $code->error);
        $this->assertStringNotContainsString(self::KEY, $rows->toJson());
    }

    public function test_details_of_one_call_never_reach_the_row_of_the_next(): void
    {
        $voice = app(VoiceTranscriber::class);

        // failure → success
        $this->fakeNeuron(Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'x']], 429));
        $voice->transcribeVoice(['file_id' => 'a1'], 'OggS');
        $this->fakeNeuron(Http::response($this->neuronOk()));
        $voice->transcribeVoice(['file_id' => 'a2'], 'OggS');
        // success → failure (HTTP, then a connection error after a plain transcribe() left its details behind)
        $this->fakeNeuron(Http::response([], 500));
        $voice->transcribeVoice(['file_id' => 'a3'], 'OggS');
        $this->fakeNeuron(Http::response($this->neuronOk()));
        $voice->transcribe('OggS');
        $this->fakeNeuron(fn () => throw new ConnectionException('down'));
        $voice->transcribeVoice(['file_id' => 'a4'], 'OggS');
        // success → silence without any details
        $this->fakeNeuron(Http::response($this->neuronOk()));
        $voice->transcribeVoice(['file_id' => 'a5'], 'OggS');
        $this->fakeNeuron(Http::response(['data' => ['text' => '']]));
        $voice->transcribeVoice(['file_id' => 'a6'], 'OggS');

        $this->assertSame([
            ['a1', 'failed', 'NeuronAI HTTP 429 (rate_limit_exceeded)', null, null, null, null, null],
            ['a2', 'ok', null, 'uz', 2.5, 'req_1', 2.5, 'Salom dunyo'],
            ['a3', 'failed', 'NeuronAI HTTP 500', null, null, null, null, null],
            ['a4', 'failed', 'NeuronAI did not respond', null, null, null, null, null],
            ['a5', 'ok', null, 'uz', 2.5, 'req_1', 2.5, 'Salom dunyo'],
            ['a6', 'empty', null, null, null, null, null, null],
        ], VoiceTranscription::orderBy('id')->get()->map(fn ($r) => [$r->file_id, $r->status, $r->error, $r->language, $r->cost, $r->request_id, $r->seconds, $r->text])->all());
    }

    public function test_the_customer_is_answered_when_the_row_cannot_be_saved(): void
    {
        Log::spy();
        $this->voiceFlowFakes(['my.neuronai.uz/*' => Http::response($this->neuronOk('Сколько стоит принтер этикеток Zebra сегодня', 5))], self::oggBody(5));
        Schema::drop('voice_transcriptions'); // e.g. the migration has not run yet

        $this->assertSame('Сколько стоит принтер этикеток Zebra сегодня', app(VoiceTranscriber::class)->transcribeVoice(['file_id' => 'x'], 'OggS')['text']);
        $this->sendVoice(['file_id' => 'f1', 'duration' => 5, 'mime_type' => 'audio/ogg'], 101);

        $messages = ChatConversation::sole()->messages;
        $this->assertSame(['Сколько стоит принтер этикеток Zebra сегодня', true], [$messages[0]['content'], $messages[0]['transcribed']]);
        $this->assertSame('A manager will give the price.', $messages[1]['content']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($reason) => $reason === 'Voice transcription: not saved')->atLeast()->once();
    }

    public function test_an_experts_voice_is_kept_with_source_expert(): void
    {
        $expert = TelegramUser::create(['telegram_id' => '501', 'name' => 'Expert', 'contact_type' => 'employee', 'is_employee' => true,
            'employee_role' => 'Service', 'answers_questions' => true, 'locale' => 'ru', 'bot_chat_at' => now()]);
        $q = ExpertQuestion::create(['question' => 'Q?', 'topic' => 'topic', 'status' => 'asked', 'expert_id' => $expert->id, 'asked_at' => now()]);
        Http::fake([
            'my.neuronai.uz/*' => Http::response($this->neuronOk('Ha, adapter bilan ishlaydi', 3)),
            'api.telegram.org/file/*' => Http::response(self::oggBody(3), 200, ['Content-Type' => 'audio/ogg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/file_1.oga', 'message_id' => 9]]),
        ]);

        $this->assertTrue(app(ExpertDesk::class)->message($expert, ['message_id' => 77, 'voice' => ['file_id' => 'exp_voice_1', 'file_unique_id' => 'exp_u1', 'duration' => 3, 'mime_type' => 'audio/ogg']]));

        $row = VoiceTranscription::sole();
        $this->assertSame(['exp_voice_1', 'exp_u1', '501', 'expert', 'neuronai', 'ok', 'Ha, adapter bilan ishlaydi'], [$row->file_id, $row->file_unique_id, $row->telegram_id, $row->source, $row->provider, $row->status, $row->text]);
        $this->assertSame('Ha, adapter bilan ishlaydi', $q->fresh()->pending['text']);

        // Too long by what the sender states: refused before the call, nothing kept.
        $this->assertTrue(app(ExpertDesk::class)->message($expert, ['message_id' => 78, 'voice' => ['file_id' => 'exp_voice_2', 'duration' => 100000]]));
        $this->assertSame(1, VoiceTranscription::count());
    }

    public function test_a_groq_transcription_is_kept_with_provider_groq(): void
    {
        config(['services.neuronai.key' => null, 'services.groq.key' => 'gsk_secret_key_555']);
        $this->voiceFlowFakes(['api.groq.com/*' => Http::response(['text' => 'Сколько стоит принтер этикеток Zebra сегодня', 'duration' => 5.0, 'language' => 'azerbaijani'])], self::oggBody(5));

        $this->sendVoice(['file_id' => 'g1', 'file_unique_id' => 'gu1', 'duration' => 5, 'mime_type' => 'audio/ogg'], 101);

        $row = VoiceTranscription::sole();
        $this->assertSame(['g1', 'gu1', '555', 101, 'bot', 'groq', 'ok', 'azerbaijan', 5.0, null, null, null],
            [$row->file_id, $row->file_unique_id, $row->telegram_id, $row->message_id, $row->source, $row->provider, $row->status, $row->language, $row->seconds, $row->cost, $row->request_id, $row->error]);
        $this->assertStringNotContainsString('gsk_secret_key_555', $row->toJson());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'neuronai'));
    }

    public function test_nothing_is_kept_when_the_voice_is_refused_before_the_call(): void
    {
        $this->voiceFlowFakes(['my.neuronai.uz/*' => Http::response($this->neuronOk('Сколько стоит принтер этикеток Zebra сегодня', 5))], self::oggBody(5));
        $user = TelegramUser::sole();
        $ok = ['file_id' => 'f1', 'duration' => 5, 'mime_type' => 'audio/ogg'];

        // A guest: NeuronAI is paid, the voice goes to staff.
        $user->update(['step' => TelegramUser::STEP_GUEST, 'name' => null, 'phone' => null, 'registered_at' => null]);
        $this->assertFalse($user->fresh()->isRegistered());
        $this->sendVoice($ok, 101);
        $user->update(['step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);

        // Too long / too big by what the sender states; no length; a format nobody reads.
        $this->sendVoice(['duration' => 500] + $ok, 102);
        $this->sendVoice(['file_size' => 50 * 1024 * 1024] + $ok, 103);
        $this->sendVoice(['file_id' => 'f1', 'mime_type' => 'audio/ogg'], 104);
        $this->sendVoice(['file_name' => 'a.amr', 'mime_type' => 'audio/amr'] + $ok, 105, 'audio');

        // The daily caps and the per-customer cap.
        config(['services.groq.voice_daily_limit' => 0]);
        $this->sendVoice($ok, 106);
        config(['services.groq.voice_daily_limit' => 300, 'services.groq.voice_daily_seconds' => 0]);
        $this->sendVoice($ok, 107);
        config(['services.groq.voice_daily_seconds' => 7200]);
        RateLimiter::clear('telegram-voice:555');
        foreach (range(1, 10) as $i) {
            RateLimiter::hit('telegram-voice:555', 3600);
        }
        $this->sendVoice($ok, 108);

        $this->assertSame(0, VoiceTranscription::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'neuronai'));

        // Control: the same voice is kept once nothing refuses it.
        RateLimiter::clear('telegram-voice:555');
        Cache::forget('telegram-voice:day:'.now()->toDateString());
        $this->sendVoice($ok, 109);
        $this->assertSame(['f1', 109, 'ok'], [VoiceTranscription::sole()->file_id, VoiceTranscription::sole()->message_id, VoiceTranscription::sole()->status]);
    }

    public function test_nothing_is_kept_for_audio_neuronai_cannot_measure(): void
    {
        // Not Ogg: its length is only what the sender says.
        $this->voiceFlowFakes(['my.neuronai.uz/*' => Http::response($this->neuronOk())], 'ID3-not-ogg');
        $this->sendVoice(['file_id' => 'a1', 'duration' => 3, 'file_name' => 'a.mp3', 'mime_type' => 'audio/mpeg'], 101, 'audio');
        $this->sendVoice(['file_id' => 'a2', 'duration' => 3, 'mime_type' => 'audio/ogg'], 102);

        $this->assertSame(0, VoiceTranscription::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'neuronai'));
    }

    public function test_nothing_is_kept_for_an_ogg_file_longer_than_its_message_says(): void
    {
        $this->voiceFlowFakes(['my.neuronai.uz/*' => Http::response($this->neuronOk())], self::oggBody(300));
        $this->sendVoice(['file_id' => 'a1', 'duration' => 3, 'mime_type' => 'audio/ogg'], 101);

        $this->assertSame(0, VoiceTranscription::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'neuronai'));
    }
}
