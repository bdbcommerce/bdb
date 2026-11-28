<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\VoiceTranscription;
use App\Services\Ai\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Voice messages are transcribed by NeuronAI when NEURONAI_API_KEY is set; Groq only without it. */
class NeuronAiVoiceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://my.neuronai.uz/api/v1/stt/transcribe';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.neuronai.key' => 'nai_test_key_123', 'services.neuronai.url' => 'https://my.neuronai.uz/api/v1', 'services.neuronai.language' => 'auto',
            'services.groq.key' => 'gsk_old_key_123',
            'services.telegram.bot_token' => 'BOT', 'services.telegram.chat_id' => '-100123', 'services.telegram.webhook_secret' => 'secret_123',
            'services.telegram.voice' => 'transcribe', 'services.gemini.key' => 'test-key',
        ]);
    }

    private function ok(string $text = 'Assalomu alaykum!', float $seconds = 3.2): array
    {
        return ['data' => ['text' => $text, 'language' => 'uz', 'duration_seconds' => $seconds, 'segments' => [], 'cost' => 3.2, 'balance' => 900], 'request_id' => 'r1'];
    }

    /** A real Ogg Opus file of the given length (as Telegram voice is). */
    private static function ogg(float $seconds, int $bodyBytes = 200): string
    {
        $page = fn (int $granule, string $body) => 'OggS'."\0\0".pack('P', $granule).pack('V', 1).pack('V', 0).pack('V', 0)
            .chr(intdiv(strlen($body), 255) + 1).str_repeat("\xFF", intdiv(strlen($body), 255)).chr(strlen($body) % 255).$body;

        return $page(0, 'OpusHead'.str_repeat("\0", 11)).$page((int) ($seconds * 48000), str_repeat('a', $bodyBytes));
    }

    /** Every second is billed: a guest's voice goes to staff, and a file whose length cannot be measured is not sent. */
    public function test_guests_and_unmeasurable_audio_do_not_reach_neuronai(): void
    {
        Http::fake([
            'my.neuronai.uz/*' => Http::response($this->ok()),
            'api.telegram.org/file/*' => Http::response('ID3-not-ogg', 200, ['Content-Type' => 'audio/mpeg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'music/file_1.mp3', 'message_id' => 9]]),
        ]);
        $send = fn (int $id, array $media, string $key) => $this->postJson('/telegram/webhook', ['update_id' => $id, 'message' => [
            'message_id' => 100 + $id, 'chat' => ['id' => $id, 'type' => 'private'], 'from' => ['id' => $id, 'first_name' => 'A'], 'date' => time(), $key => $media,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'secret_123'])->assertOk();

        TelegramUser::create(['telegram_id' => '701', 'locale' => 'ru', 'step' => TelegramUser::STEP_GUEST]);
        $send(701, ['file_id' => 'v1', 'duration' => 5, 'mime_type' => 'audio/ogg'], 'voice');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/forwardMessage') && (string) $r['chat_id'] === '-100123');

        TelegramUser::create(['telegram_id' => '702', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        $send(702, ['file_id' => 'a1', 'duration' => 1, 'file_name' => 'a.mp3', 'mime_type' => 'audio/mpeg'], 'audio');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'neuronai'));
    }

    public function test_neuronai_is_called_and_its_answer_is_read(): void
    {
        Http::fake(['my.neuronai.uz/*' => Http::response($this->ok())]);
        $voice = app(VoiceTranscriber::class);

        $this->assertSame(['neuronai', 'NeuronAI', 'nai_test_key_123'], [$voice->provider(), $voice->providerName(), $voice->key()]);
        $this->assertSame(['text' => 'Assalomu alaykum!', 'seconds' => 3.2], $voice->transcribe('OggS-audio', 'voice.ogg'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL && $r->isMultipart() && $r->hasHeader('Authorization', 'Bearer nai_test_key_123')
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'file' && $part['filename'] === 'voice.ogg')
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'language' && $part['contents'] === 'auto')
            && ! collect($r->data())->contains(fn ($part) => $part['name'] === 'model'));
    }

    public function test_language_and_address_come_from_the_settings(): void
    {
        Http::fake(['*' => Http::response($this->ok('', 1.0))]);
        config(['services.neuronai.language' => 'UZ', 'services.neuronai.url' => 'http://10.0.0.5:8080/api/v1']);

        // Silence: no text, the seconds are still counted.
        $this->assertSame(['text' => null, 'seconds' => 1.0], app(VoiceTranscriber::class)->transcribe('OggS-audio'));
        Http::assertSent(fn (Request $r) => $r->url() === 'http://10.0.0.5:8080/api/v1/stt/transcribe'
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'language' && $part['contents'] === 'uz'));

        config(['services.neuronai.language' => 'klingon']);
        $this->assertSame('auto', app(VoiceTranscriber::class)->language());
    }

    public function test_errors_alert_admins_without_the_key(): void
    {
        Http::fake([
            'my.neuronai.uz/*' => Http::response(['error' => ['type' => 'insufficient_quota', 'code' => 'insufficient_balance', 'message' => 'No credits left for nai_test_key_123', 'param' => null]], 402),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS-audio'));

        $alert = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($pair) => $pair[0]['text'])->implode("\n");
        $this->assertStringContainsString('NeuronAI HTTP 402 (insufficient_balance)', $alert);
        $this->assertStringNotContainsString('nai_test_key_123', $alert);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
    }

    /** A 200 without "data" (an error object, a login page) is a failure with an alert, not a silent recording. */
    public function test_a_200_answer_without_data_alerts_admins(): void
    {
        Http::fake([
            'my.neuronai.uz/*' => Http::sequence()->push(['error' => ['code' => 'insufficient_balance', 'message' => 'No credits']])->push('<html>Login</html>'),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS-audio'));
        $this->assertNull(app(VoiceTranscriber::class)->transcribe('OggS-audio'));

        $alert = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->map(fn ($pair) => $pair[0]['text'])->implode("\n");
        $this->assertStringContainsString('NeuronAI sent an answer without data', $alert);
        $this->assertStringContainsString('No credits', $alert);
    }

    public function test_without_a_neuronai_key_groq_is_still_used(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['text' => 'Salom', 'duration' => 2])]);
        config(['services.neuronai.key' => null]);
        $voice = app(VoiceTranscriber::class);

        $this->assertSame('groq', $voice->provider());
        $this->assertSame(['text' => 'Salom', 'seconds' => 2.0], $voice->transcribe('OggS-audio'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'neuronai'));
    }

    public function test_a_voice_message_in_the_bot_chat_goes_through_neuronai(): void
    {
        TelegramUser::create(['telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE, 'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now()]);
        Http::fake([
            'my.neuronai.uz/*' => Http::response($this->ok('Сколько стоит принтер этикеток Zebra', 12)),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['reply' => 'A manager will give the price.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true])]]]]]]),
            'api.telegram.org/file/*' => Http::response(self::ogg(12), 200, ['Content-Type' => 'audio/ogg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/file_1.oga', 'message_id' => 9]]),
        ]);

        $this->postJson('/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 101, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Aziz'], 'date' => time(),
            'voice' => ['file_id' => 'voice_file_1', 'file_unique_id' => 'uniq_1', 'duration' => 12, 'mime_type' => 'audio/ogg', 'file_size' => 40000],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'secret_123'])->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === self::URL);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $messages = ChatConversation::sole()->messages;
        $this->assertSame(['Сколько стоит принтер этикеток Zebra', true], [$messages[0]['content'], $messages[0]['transcribed']]);

        // The Telegram file ID and what NeuronAI returned are kept.
        $saved = VoiceTranscription::sole();
        $this->assertSame(
            ['voice_file_1', 'uniq_1', '555', 101, 'bot', 'neuronai', 'ok', 'Сколько стоит принтер этикеток Zebra', 'uz', 12.0, 3.2, 'r1', null],
            [$saved->file_id, $saved->file_unique_id, $saved->telegram_id, $saved->message_id, $saved->source, $saved->provider, $saved->status, $saved->text, $saved->language, $saved->seconds, $saved->cost, $saved->request_id, $saved->error],
        );
    }

    public function test_failed_and_silent_transcriptions_are_kept_too(): void
    {
        Http::fake([
            'my.neuronai.uz/*' => Http::sequence()->push(['error' => ['code' => 'insufficient_balance', 'message' => 'No credits for nai_test_key_123']], 402)->push($this->ok('', 2.0)),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $voice = app(VoiceTranscriber::class);

        $this->assertNull($voice->transcribeVoice(['file_id' => 'f1', 'telegram_id' => 555, 'source' => 'expert'], 'OggS-audio'));
        $this->assertSame(['text' => null, 'seconds' => 2.0], $voice->transcribeVoice(['file_id' => 'f2'], 'OggS-audio'));
        // Without a file ID there is nothing to keep.
        $voice->transcribeVoice([], 'OggS-audio');

        $rows = VoiceTranscription::orderBy('id')->get();
        $this->assertSame([['f1', 'failed', 'expert', 'NeuronAI HTTP 402 (insufficient_balance)', null], ['f2', 'empty', 'bot', null, null]],
            $rows->map(fn ($r) => [$r->file_id, $r->status, $r->source, $r->error, $r->text])->all());
        $this->assertStringNotContainsString('nai_test_key_123', $rows->toJson());
    }

    public function test_ai_settings_page_names_neuronai(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()
            ->assertSee('transcribed by NeuronAI')->assertSee('NEURONAI_API_KEY in .env')->assertDontSee('nai_test_key_123');

        config(['services.neuronai.key' => null, 'services.groq.key' => null]);
        $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()->assertSee('off — set NEURONAI_API_KEY in .env');
    }
}
