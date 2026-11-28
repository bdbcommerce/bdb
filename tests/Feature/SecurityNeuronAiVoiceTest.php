<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\OnecItem;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Ai\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Security checks for the NeuronAI speech-to-text path (paid: 1 credit per second of audio) and the
 * /admin/catalog redirect. All HTTP is faked. Tests marked FINDING fail until the finding is fixed.
 */
class SecurityNeuronAiVoiceTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'nai_secret_key_987654';

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    private int $messageId = 100;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.neuronai.key' => self::KEY, 'services.neuronai.url' => 'https://my.neuronai.uz/api/v1', 'services.neuronai.language' => 'auto',
            'services.groq.key' => null,
            'services.telegram.bot_token' => '123456:BOT_TOKEN_SECRET', 'services.telegram.chat_id' => '-100123',
            'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.voice' => 'transcribe',
            'services.telegram.replies' => true, 'services.gemini.key' => 'test-gemini-key',
            'services.groq.voice_daily_limit' => 300, 'services.groq.voice_daily_seconds' => 7200,
        ]);
    }

    private function registered(): TelegramUser
    {
        return TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
    }

    private mixed $neuron = null;

    private string $audio = '';

    private string $path = '';

    private bool $faked = false;

    /** Http::fake keeps the first stub for a URL, so the stubs read these properties: later calls change the answers. */
    private function fakeApis(array|callable $neuron, ?string $audio = null, string $path = 'voice/file_1.oga'): void
    {
        // A real 5-second Ogg Opus file: a paid transcriber is sent only audio whose length can be measured.
        $page = fn (int $granule, string $body) => 'OggS'."\0\0".pack('P', $granule).pack('V', 1).pack('V', 0).pack('V', 0).chr(1).chr(strlen($body)).$body;
        $audio ??= $page(0, 'OpusHead'.str_repeat("\0", 11)).$page(5 * 48000, str_repeat('a', 200));
        [$this->neuron, $this->audio, $this->path] = [$neuron, $audio, $path];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'my.neuronai.uz/*' => fn () => is_callable($this->neuron) ? ($this->neuron)() : Http::response($this->neuron),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'A manager will tell you the price.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true])]]]]]]),
            'api.telegram.org/file/*' => fn () => Http::response($this->audio, 200, ['Content-Type' => 'audio/ogg']),
            'api.telegram.org/*' => fn () => Http::response(['ok' => true, 'result' => ['file_path' => $this->path, 'message_id' => 9]]),
        ]);
    }

    private function ok(string $text = 'Сколько стоит принтер этикеток Zebra', ?float $seconds = 5.0): array
    {
        return ['data' => ['text' => $text] + ($seconds === null ? [] : ['duration_seconds' => $seconds])];
    }

    private function send(array $media, string $field = 'voice', int $from = 555): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => ++$this->messageId, 'chat' => ['id' => $from, 'type' => 'private'], 'from' => ['id' => $from, 'first_name' => 'Aziz'], 'date' => time(),
            $field => $media,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function neuronCalls(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'neuronai'))->count();
    }

    private function alerts(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) ($r['chat_id'] ?? '') === '-100123')
            ->map(fn ($pair) => (string) $pair[0]['text'])->values()->all();
    }

    // --- Secrets ---------------------------------------------------------------

    public function test_key_is_masked_in_alert_details_logs_and_admin_page(): void
    {
        Log::spy();
        $this->registered();
        $this->fakeApis(fn () => Http::response(['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key '.self::KEY.' bot 123456:BOT_TOKEN_SECRET']], 401));

        $this->send(['file_id' => 'v1', 'duration' => 5]);

        $this->assertSame(1, $this->neuronCalls());
        $all = implode("\n", Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($p) => (string) $p[0]['text'])->all());
        $this->assertStringContainsString('NeuronAI HTTP 401', $all);
        $this->assertStringNotContainsString(self::KEY, $all);
        $this->assertStringNotContainsString('BOT_TOKEN_SECRET', $all);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'NeuronAI')
            && ! str_contains($message.json_encode($context), self::KEY) && ! str_contains($message.json_encode($context), 'BOT_TOKEN_SECRET'));

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.ai-models.index'))
            ->assertOk()->assertDontSee(self::KEY)->assertDontSee('BOT_TOKEN_SECRET');
    }

    public function test_key_is_masked_when_the_connection_fails(): void
    {
        Log::spy();
        Http::fake([
            'my.neuronai.uz/*' => fn () => throw new ConnectionException('cURL error 28: timed out, Authorization: Bearer '.self::KEY),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS-audio'));

        $this->assertStringNotContainsString(self::KEY, implode("\n", $this->alerts()));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), self::KEY));
    }

    /**
     * FINDING (fails until fixed): error.code from the NeuronAI response is put into the alert's *reason* (and the
     * log message) as is — only the details are passed through withoutSecrets. A response that echoes the key in
     * error.code puts the key into the Telegram alert and laravel.log.
     */
    public function test_error_code_from_the_response_cannot_carry_the_key_into_alert_or_log(): void
    {
        Log::spy();
        Http::fake([
            'my.neuronai.uz/*' => Http::response(['error' => ['code' => 'bad_key:'.self::KEY, 'message' => 'x']], 401),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        app(VoiceTranscriber::class)->transcribe('OggS-audio');

        $this->assertStringNotContainsString(self::KEY, implode("\n", $this->alerts()), 'the key reached the Telegram alert through error.code');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), self::KEY));
    }

    /**
     * FINDING (fails until fixed): the alert throttle is keyed on md5(reason) and the reason contains error.code
     * from the response, so a response with a different code each time gets a new alert each time (no 15 min mute),
     * and the code has no length cap or character whitelist.
     */
    public function test_changing_error_codes_do_not_defeat_the_alert_throttle(): void
    {
        $n = 0;
        Http::fake([
            'my.neuronai.uz/*' => function () use (&$n) {
                return Http::response(['error' => ['code' => 'req_'.(++$n)."\nINJECTED LINE ".str_repeat('A', 300), 'message' => 'x']], 500);
            },
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        for ($i = 0; $i < 5; $i++) {
            app(VoiceTranscriber::class)->transcribe('OggS-audio');
        }

        $alerts = $this->alerts();
        $this->assertLessThanOrEqual(1, count($alerts), 'one problem (NeuronAI HTTP 500) must alert once per 15 minutes');
        $this->assertStringNotContainsString('INJECTED LINE', implode("\n", $alerts));
    }

    // --- Untrusted response content --------------------------------------------

    public function test_transcript_is_bounded_plain_text_for_the_customer_and_escaped_for_admins(): void
    {
        $this->registered();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)> '.str_repeat('я', 9000);
        $this->fakeApis($this->ok($payload));

        $this->send(['file_id' => 'v1', 'duration' => 5]);

        $conversation = ChatConversation::sole();
        $this->assertLessThanOrEqual(3000, mb_strlen($conversation->messages[0]['content']));
        $echo = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && str_contains((string) $r['text'], '🎙'))->first()[0];
        $this->assertArrayNotHasKey('parse_mode', $echo->data());
        $this->assertLessThanOrEqual(4096, mb_strlen($echo['text']));

        // The AI gets at most 1000 characters, as a user turn only.
        $gemini = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generateContent'))->first()[0]->data();
        $last = end($gemini['contents']);
        $this->assertLessThanOrEqual(1000, mb_strlen($last['parts'][0]['text']));
        $this->assertStringNotContainsString('<script>alert(1)', json_encode($gemini['systemInstruction'] ?? $gemini['system_instruction'] ?? []));

        $admin = User::factory()->create(['is_admin' => true]);
        foreach ([route('admin.chat-conversations.show', $conversation), route('admin.chat-conversations.index'), route('admin.telegram-users.show', TelegramUser::sole())] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
        }
    }

    public function test_odd_response_shapes_do_not_throw_or_answer(): void
    {
        $this->registered();
        foreach ([['data' => ['text' => ['a' => 'b'], 'duration_seconds' => 'x']], ['data' => 'text'], ['error' => ['message' => ['nested']]], []] as $body) {
            $this->fakeApis($body);
            $this->send(['file_id' => 'v1', 'duration' => 5]);
        }
        $this->fakeApis(fn () => Http::response('<html>not json</html>', 502));
        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS-audio'));

        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), 'generateContent'))->count());
    }

    // --- Limits before a paid call ---------------------------------------------

    public function test_claimed_length_size_and_real_ogg_length_are_checked_before_neuronai(): void
    {
        $this->registered();
        $this->fakeApis($this->ok());

        $this->send(['file_id' => 'v1', 'duration' => 61]);
        $this->assertSame(0, $this->neuronCalls(), 'claimed 61 s');
        $this->send(['file_id' => 'v2', 'duration' => 5, 'file_size' => 2 * 1024 * 1024]);
        $this->assertSame(0, $this->neuronCalls(), 'claimed 2 MB');
        $this->send(['file_id' => 'v3', 'duration' => 5, 'file_name' => 'a.aac', 'mime_type' => 'audio/aac'], 'audio');
        $this->assertSame(0, $this->neuronCalls(), 'aac');

        // A body over 1.5 MB is refused after the download, whatever the sender claimed.
        $this->fakeApis($this->ok(), str_repeat('A', 1600 * 1024));
        $this->send(['file_id' => 'v4', 'duration' => 5]);
        $this->assertSame(0, $this->neuronCalls());

        // A real Ogg Opus page whose granule position says 10 minutes.
        $page = 'OggS'."\x00\x00".pack('P', 600 * 48000).str_repeat("\x00", 12)."\x01"."\x13".'OpusHead'.str_repeat("\x00", 11);
        $this->fakeApis($this->ok(), $page);
        $this->send(['file_id' => 'v5', 'duration' => 5]);
        $this->assertSame(0, $this->neuronCalls());
    }

    public function test_per_customer_and_daily_limits_stop_neuronai_calls(): void
    {
        $this->registered();
        $this->fakeApis($this->ok());

        for ($i = 0; $i < 12; $i++) {
            $this->send(['file_id' => "v{$i}", 'duration' => 5]);
        }
        $this->assertSame(10, $this->neuronCalls(), 'at most 10 transcriptions per customer per hour');

        // The daily budget of audio seconds, counted from what NeuronAI measured.
        $other = TelegramUser::create(['telegram_id' => '777', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Vali', 'phone' => '+998901234568', 'registered_at' => now()]);
        $this->assertSame(50, (int) Cache::get('telegram-voice:seconds:'.now()->toDateString()));
        config(['services.groq.voice_daily_seconds' => 50]);
        $this->send(['file_id' => 'w1', 'duration' => 5], 'voice', (int) $other->telegram_id);
        $this->assertSame(10, $this->neuronCalls(), 'no call once the daily seconds budget is used');

        config(['services.groq.voice_daily_seconds' => 7200, 'services.groq.voice_daily_limit' => 0]);
        $this->send(['file_id' => 'w2', 'duration' => 5], 'voice', (int) $other->telegram_id);
        $this->assertSame(10, $this->neuronCalls(), 'no call once the daily message cap is used');
    }

    public function test_forward_mode_group_and_record_only_never_reach_neuronai(): void
    {
        $this->registered();
        $this->fakeApis($this->ok());

        config(['services.telegram.voice' => 'forward']);
        $this->send(['file_id' => 'v1', 'duration' => 5]);

        config(['services.telegram.voice' => 'transcribe', 'services.telegram.replies' => false]);
        $this->send(['file_id' => 'v2', 'duration' => 5]);

        config(['services.telegram.replies' => true]);
        $this->postJson('/telegram/webhook', ['update_id' => $this->updateId++, 'message' => [
            'message_id' => 900, 'chat' => ['id' => -100999, 'type' => 'supergroup', 'title' => 'G'], 'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(),
            'voice' => ['file_id' => 'v3', 'duration' => 5],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $this->assertSame(0, $this->neuronCalls());
    }

    /**
     * FINDING (fails until fixed): CLAUDE.md says only registered customers get transcription, but any
     * Telegram account that picked a language (a guest) reaches the paid NeuronAI call; the guest AI caps
     * (guestMayAsk) are only checked afterwards, in answer().
     */
    public function test_unregistered_guests_do_not_spend_neuronai_credits(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST]);
        $this->fakeApis($this->ok());

        $this->send(['file_id' => 'v1', 'duration' => 5]);

        $this->assertSame(0, $this->neuronCalls(), 'an unregistered Telegram account triggered a paid transcription');
    }

    /**
     * FINDING (fails until fixed): for audio that is not Ogg Opus (mp3, m4a, webm…) the only length known before
     * the paid call is the sender's own "duration". A 1.4 MB low-bitrate MP3 (about 25 minutes at 8 kbit/s)
     * claiming 1 second is sent to NeuronAI in full; and when the response has no data.duration_seconds the daily
     * budget counts the claimed 1 second.
     */
    public function test_non_ogg_audio_with_a_false_length_is_not_sent_or_is_counted_in_full(): void
    {
        $this->registered();
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x10\x00".str_repeat("\x00", 20), 60000); // ~1.4 MB
        $this->fakeApis($this->ok(seconds: null), $mp3, 'music/file_1.mp3');

        $this->send(['file_id' => 'a1', 'duration' => 1, 'file_name' => 'a.mp3', 'mime_type' => 'audio/mpeg'], 'audio');

        $counted = (int) Cache::get('telegram-voice:seconds:'.now()->toDateString());
        $this->assertTrue($this->neuronCalls() === 0 || $counted > 60,
            "a 1.4 MB MP3 claiming 1 s was sent to NeuronAI ({$this->neuronCalls()} call) and the daily budget counted {$counted} s");
    }

    // --- /admin/catalog redirect -------------------------------------------------

    public function test_catalog_redirect_is_behind_the_admin_middleware_and_is_not_open(): void
    {
        OnecItem::create(['onec_id' => 'g1', 'code' => 7, 'name' => 'Xprinter XP-420B']);

        $this->get('/admin/catalog')->assertRedirect(route('admin.login'));
        $this->get('/admin/products')->assertRedirect(route('admin.login'));

        $user = User::factory()->create(['is_admin' => false]);
        $response = $this->actingAs($user)->get('/admin/catalog');
        $this->assertNotSame(route('admin.products.index'), $response->headers->get('Location'));
        $this->assertFalse($response->isOk());

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/catalog')->assertRedirect(route('admin.products.index'));
        // No request input steers the redirect: any query string turns it off, and the target is fixed.
        foreach (['next' => 'https://evil.example', 'redirect' => '//evil.example', 'url' => 'https://evil.example'] as $name => $value) {
            $this->actingAs($admin)->get('/admin/catalog?'.http_build_query([$name => $value]))->assertOk();
        }
        $this->actingAs($admin)->withHeader('Referer', 'https://evil.example/x')->get('/admin/catalog')->assertRedirect(route('admin.products.index'));
    }
}
