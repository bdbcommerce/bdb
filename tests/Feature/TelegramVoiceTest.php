<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ChatConversation;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Ai\VoiceTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Voice messages up to a minute in the bot chat: transcribed by Groq Whisper, transcript sent back, then answered. */
class TelegramVoiceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_webhook_secret_123';

    private int $updateId = 1;

    private int $messageId = 100;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'BOT',
            'services.telegram.chat_id' => '-100123',
            'services.telegram.webhook_secret' => self::SECRET,
            'services.gemini.key' => 'test-key',
            'services.groq.key' => 'gsk_test_key_123',
            'services.groq.voice_daily_limit' => 300,
        ]);

        TelegramUser::create([
            'telegram_id' => '555', 'locale' => 'ru', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);
    }

    private function fakeApis(?string $transcript = 'Сколько стоит принтер этикеток Zebra', int $groqStatus = 200, string $audio = 'OggS-fake-audio', ?float $groqSeconds = null): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response($groqStatus === 200 ? ['text' => $transcript] + ($groqSeconds !== null ? ['duration' => $groqSeconds] : []) : ['error' => ['message' => 'Rate limit reached for gsk_test_key_123']], $groqStatus),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'Zebra label printers start from a desktop model; a manager will give the price.',
                    'suggestions' => [], 'offer_callback' => false, 'answered' => true,
                ])]]]]],
            ]),
            'api.telegram.org/file/*' => Http::response($audio, 200, ['Content-Type' => 'audio/ogg']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/file_1.oga', 'message_id' => 9]]),
        ]);
    }

    private function voice(array $voice = [], string $key = 'voice')
    {
        return $this->postJson('/telegram/webhook', [
            'update_id' => $this->updateId++,
            'message' => [
                'message_id' => ++$this->messageId,
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 555, 'first_name' => 'Aziz'],
                'date' => time(),
                $key => $voice + ['file_id' => 'voice_file_1', 'duration' => 12, 'mime_type' => 'audio/ogg', 'file_size' => 40000],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<string> texts sent to the customer */
    private function sent(int|string|null $chatId = null): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && ($chatId === null || (string) $r['chat_id'] === (string) $chatId))
            ->map(fn ($pair) => $pair[0]['text'])->values()->all();
    }

    public function test_voice_is_transcribed_replied_and_answered(): void
    {
        $this->fakeApis();

        $this->voice();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'file' && $part['filename'] === 'voice.ogg')
            && $r->hasHeader('Authorization', 'Bearer gsk_test_key_123') && $r->isMultipart()
            && collect($r->data())->contains(fn ($part) => $part['name'] === 'model' && $part['contents'] === 'whisper-large-v3'));

        $this->assertSame(['🎙 Сколько стоит принтер этикеток Zebra', 'Zebra label printers start from a desktop model; a manager will give the price.'], $this->sent());
        $transcriptReply = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->first()[0];
        $this->assertSame(101, $transcriptReply['reply_parameters']['message_id']);
        $this->assertArrayNotHasKey('parse_mode', $transcriptReply->data());

        // The AI got the transcript as the question.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateContent') && str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Сколько стоит принтер этикеток Zebra'));

        $messages = ChatConversation::sole()->messages;
        $this->assertSame('voice', $messages[0]['type']);
        $this->assertSame('voice_file_1', $messages[0]['file_id']);
        $this->assertSame('Сколько стоит принтер этикеток Zebra', $messages[0]['content']);
        $this->assertTrue($messages[0]['transcribed']);
        $this->assertArrayNotHasKey('aside', $messages[0], 'answered: part of the AI dialogue');
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertCount(2, ChatConversation::sole()->textMessages());
    }

    public function test_short_voice_gets_a_creative_reply(): void
    {
        $this->fakeApis('Салом');

        $this->voice(['duration' => 1]);

        $this->assertSame('🎙 Салом', $this->sent()[0]);
        $this->assertCount(2, $this->sent());
    }

    public function test_voice_over_a_minute_is_not_transcribed(): void
    {
        $this->fakeApis();

        $this->voice(['duration' => 61]);
        $this->voice(['duration' => 30, 'file_size' => 6 * 1024 * 1024]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_voice_too_long', ['seconds' => 60], 'ru'), __('chat.tg_voice_too_long', ['seconds' => 60], 'ru')], $this->sent());
    }

    public function test_audio_files_up_to_a_minute_are_transcribed_too(): void
    {
        $this->fakeApis();

        $this->voice(['mime_type' => 'audio/mpeg'], 'audio');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame('🎙 Сколько стоит принтер этикеток Zebra', $this->sent()[0]);
    }

    public function test_failure_tells_the_customer_and_alerts_admins_without_the_key(): void
    {
        $this->fakeApis(groqStatus: 429);

        $this->voice();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'generateContent'));
        $this->assertSame([__('chat.tg_voice_failed', [], 'ru')], $this->sent(555));
        $alert = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '-100123')->first()[0];
        $this->assertStringContainsString('Groq HTTP 429', $alert['text']);
        $this->assertStringNotContainsString('gsk_test_key_123', $alert['text']);
        $this->assertArrayNotHasKey('content', ChatConversation::sole()->messages[0]);
    }

    public function test_silence_is_not_answered(): void
    {
        $this->fakeApis('  ');

        $this->voice();

        $this->assertSame([__('chat.tg_voice_failed', [], 'ru')], $this->sent());
    }

    public function test_without_a_groq_key_voice_is_only_recorded(): void
    {
        config(['services.groq.key' => null]);
        $this->fakeApis();

        $this->voice();

        $this->assertSame([], $this->sent());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
    }

    public function test_the_key_of_a_groq_model_in_ai_settings_is_used(): void
    {
        config(['services.groq.key' => null]);
        AiModel::create(['name' => 'Groq', 'provider' => 'openai', 'model' => 'llama-3.3-70b-versatile', 'base_url' => 'https://api.groq.com/openai/v1',
            'api_key' => 'gsk_from_settings', 'tasks' => [], 'priority' => 30, 'is_active' => false]);
        $this->fakeApis();

        $this->voice();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'groq.com') && $r->hasHeader('Authorization', 'Bearer gsk_from_settings'));
    }

    public function test_hourly_and_daily_limits(): void
    {
        $this->fakeApis('Сколько стоит доставка в Самарканд');

        for ($i = 0; $i < 11; $i++) {
            $this->voice();
        }
        $this->assertSame(10, Http::recorded(fn (Request $r) => str_contains($r->url(), 'groq.com'))->count());
        $this->assertSame(__('chat.tg_voice_limit', [], 'ru'), last($this->sent()));

        config(['services.groq.voice_daily_limit' => 0]);
        TelegramUser::create(['telegram_id' => '777', 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE, 'name' => 'Bek', 'phone' => '+998901112233', 'registered_at' => now()]);
        $this->postJson('/telegram/webhook', [
            'update_id' => $this->updateId++,
            'message' => ['message_id' => 5, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'first_name' => 'Bek'], 'date' => time(),
                'voice' => ['file_id' => 'v2', 'duration' => 5]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->assertSame(10, Http::recorded(fn (Request $r) => str_contains($r->url(), 'groq.com'))->count());
        $this->assertSame(__('chat.tg_voice_limit', [], 'uz'), last($this->sent()));
    }

    public function test_unregistered_customers_and_record_only_mode_get_no_transcription(): void
    {
        $this->fakeApis();
        TelegramUser::where('telegram_id', '555')->update(['step' => TelegramUser::STEP_PHONE, 'registered_at' => null]);

        $this->voice();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));

        TelegramUser::where('telegram_id', '555')->update(['step' => TelegramUser::STEP_DONE, 'registered_at' => now()]);
        config(['services.telegram.replies' => false]);
        $this->voice();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
    }

    public function test_admin_sees_player_and_transcript(): void
    {
        $this->fakeApis();
        $this->voice();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.chat-conversations.show', ChatConversation::sole()))
            ->assertOk()
            ->assertSee('Transcript:')
            ->assertSee('Сколько стоит принтер этикеток Zebra')
            ->assertSee(route('admin.telegram.media', 'voice_file_1'), false);
    }

    public function test_caption_edit_keeps_the_transcript(): void
    {
        $this->fakeApis();
        $this->voice();

        $this->postJson('/telegram/webhook', [
            'update_id' => $this->updateId++,
            'edited_message' => ['message_id' => 101, 'chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555], 'date' => time(),
                'voice' => ['file_id' => 'voice_file_1', 'duration' => 12], 'caption' => 'price?'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();

        $m = ChatConversation::sole()->messages[0];
        $this->assertSame(['Сколько стоит принтер этикеток Zebra', 'price?', true], [$m['content'], $m['caption'], $m['edited']]);
    }

    public function test_ai_settings_show_the_voice_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.ai-models.index'))->assertOk()->assertSee('Telegram voice messages:')->assertSee('GROQ_API_KEY in .env')->assertDontSee('gsk_test_key_123');

        config(['services.groq.key' => null]);
        $this->get(route('admin.ai-models.index'))->assertOk()->assertSee('Until then voice messages are only saved in the chat.');
    }

    /** A minimal Ogg Opus file whose last page says it is $seconds long. */
    private static function ogg(float $seconds, int $bodyBytes = 200): string
    {
        $page = fn (int $granule, string $body) => 'OggS'."\0\0".pack('P', $granule).pack('V', 1).pack('V', 0).pack('V', 0)
            .chr(intdiv(strlen($body), 255) + 1).str_repeat("\xFF", intdiv(strlen($body), 255)).chr(strlen($body) % 255).$body;

        return $page(0, 'OpusHead'.str_repeat("\0", 11)).$page((int) ($seconds * 48000), str_repeat('a', $bodyBytes));
    }

    public function test_ogg_length_is_read_from_the_file(): void
    {
        $this->assertEqualsWithDelta(12.5, VoiceTranscriber::oggSeconds(self::ogg(12.5)), 0.001);
        $this->assertNull(VoiceTranscriber::oggSeconds('OggS-fake-audio'));
        $this->assertNull(VoiceTranscriber::oggSeconds(self::ogg(5).'OggS-trailing-junk'), 'pages must reach the end exactly');
    }

    public function test_a_voice_that_lies_about_its_length_is_not_transcribed(): void
    {
        $this->fakeApis(audio: self::ogg(600));

        $this->voice(['duration' => 5]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_voice_too_long', ['seconds' => 60], 'ru')], $this->sent(555));
    }

    public function test_a_file_too_big_by_telegrams_own_size_is_not_downloaded(): void
    {
        Http::fake([
            'api.telegram.org/file/*' => Http::response('x'),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['file_path' => 'voice/f.oga', 'file_size' => 4_000_000]]),
            '*' => Http::response([]),
        ]);

        $this->voice(['file_size' => 1000]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/file/bot') || str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_voice_failed', [], 'ru')], $this->sent(555));
        $this->assertNotEmpty($this->sent('-100123'), 'admins are alerted');
    }

    public function test_audio_seconds_measured_by_groq_count_against_the_daily_budget(): void
    {
        config(['services.groq.voice_daily_seconds' => 100]);
        $this->fakeApis(groqSeconds: 95.2);

        $this->voice(['mime_type' => 'audio/mpeg'], 'audio');
        $this->assertSame([__('chat.tg_voice_too_long', ['seconds' => 60], 'ru')], $this->sent(555), 'over a minute as measured: not answered');

        $this->voice(); // 96 of 100 s used: still allowed (and now over)
        $this->assertSame(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'groq.com'))->count());

        $this->voice();
        $this->assertSame(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'groq.com'))->count());
        $this->assertSame(__('chat.tg_voice_limit', [], 'ru'), last($this->sent(555)));
    }

    public function test_whisper_silence_inventions_are_not_answered(): void
    {
        foreach (['Субтитры сделал DimaTorzok', 'Продолжение следует...', 'Thank you.'] as $text) {
            $this->assertTrue(VoiceTranscriber::isHallucination($text), $text);
        }
        $this->assertFalse(VoiceTranscriber::isHallucination('Thank you, I need a Zebra printer'));
        $this->fakeApis('Продолжение следует...');

        $this->voice();

        $this->assertSame([__('chat.tg_voice_failed', [], 'ru')], $this->sent(555));
    }

    public function test_unsupported_formats_are_not_sent(): void
    {
        $this->fakeApis();

        $this->voice(['mime_type' => 'audio/aac', 'file_name' => 'song.aac'], 'audio');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_voice_failed', [], 'ru')], $this->sent(555));
    }

    public function test_only_real_groq_hosts_and_a_key_that_decrypts(): void
    {
        config(['services.groq.key' => null]);
        $make = fn (string $url, string $key, int $priority) => AiModel::create(['name' => $url, 'provider' => 'openai', 'model' => 'm', 'base_url' => $url, 'api_key' => $key, 'tasks' => [], 'priority' => $priority, 'is_active' => true]);
        $make('https://evil.example/api.groq.com', 'gsk_wrong_host', 1);
        $broken = $make('https://api.groq.com/openai/v1', 'gsk_broken', 2);
        DB::table('ai_models')->where('id', $broken->id)->update(['api_key' => 'not-encrypted']);
        $make('https://api.groq.com/openai/v1', 'gsk_good', 3);

        $this->assertSame('gsk_good', app(VoiceTranscriber::class)->key());
    }
}
