<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CatalogSetting;
use App\Models\ChatConversation;
use App\Models\OnecItem;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Ai\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
}
