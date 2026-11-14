<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\McpSetting;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Ai\VoiceTranscriber;
use App\Services\Mcp\TeamTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Edge cases of voice transcription in the bot chat and how a transcribed entry shows up elsewhere. */
class TelegramVoiceEdgeCasesTest extends TestCase
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
            'telegram_id' => '555', 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE,
            'name' => 'Aziz', 'phone' => '+998901234567', 'registered_at' => now(),
        ]);
    }

    private function fakeApis(string $filePath = 'voice/file_1.oga', bool $getFileOk = true, string $transcript = 'Zebra printerining narxi qancha boʻladi'): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['text' => $transcript]),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'reply' => 'Narxni menejer aytadi.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
                    'topic' => 'printer price', 'category' => 'pricing',
                ])]]]]],
            ]),
            'api.telegram.org/file/*' => Http::response('fake-audio', 200),
            'api.telegram.org/botBOT/getFile' => $getFileOk
                ? Http::response(['ok' => true, 'result' => ['file_path' => $filePath]])
                : Http::response(['ok' => false, 'description' => 'Bad Request: file is too big'], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 9]]),
        ]);
    }

    private function send(array $voice = [], string $key = 'voice', string $chat = '555'): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => $this->updateId++,
            'message' => [
                'message_id' => ++$this->messageId,
                'chat' => ['id' => (int) $chat, 'type' => 'private'],
                'from' => ['id' => (int) $chat, 'first_name' => 'Aziz'],
                'date' => time(),
                $key => array_filter($voice + ['file_id' => 'voice_file_1', 'duration' => 12, 'file_size' => 40000], fn ($v) => $v !== null),
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<string> */
    private function sent(string $chatId = '555'): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === $chatId)
            ->map(fn ($pair) => $pair[0]['text'])->values()->all();
    }

    private function groqFilename(): ?string
    {
        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), 'groq.com'))->first()[0] ?? null;

        return $request ? collect($request->data())->firstWhere('name', 'file')['filename'] ?? null : null;
    }

    public function test_filename_mapping(): void
    {
        $this->assertSame('voice.ogg', VoiceTranscriber::filename('file_1.oga'));
        $this->assertSame('voice.ogg', VoiceTranscriber::filename(null));
        $this->assertSame('voice.ogg', VoiceTranscriber::filename('file.OPUS'));
        $this->assertSame('audio.m4a', VoiceTranscriber::filename('file_2.m4a'));
        $this->assertSame('audio.mp3', VoiceTranscriber::filename('file_3.MP3'));
        $this->assertNull(VoiceTranscriber::filename('file_4.aac'), 'unsupported types are not sent');
        $this->assertNull(VoiceTranscriber::filename(null, 'audio/amr'));
        $this->assertSame('audio.mp3', VoiceTranscriber::filename(null, 'audio/mpeg'));
    }

    public function test_audio_m4a_and_mp3_keep_their_extension(): void
    {
        $this->fakeApis('music/file_2.m4a');
        $this->send(['mime_type' => 'audio/mp4'], 'audio');
        $this->assertSame('audio.m4a', $this->groqFilename());
    }

    public function test_audio_mp3_keeps_its_extension(): void
    {
        $this->fakeApis('music/file_3.mp3');
        $this->send(['mime_type' => 'audio/mpeg'], 'audio');
        $this->assertSame('audio.mp3', $this->groqFilename());
    }

    public function test_missing_duration_gets_the_could_not_hear_reply(): void
    {
        $this->fakeApis();
        $this->send(['duration' => null]);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_voice_failed', [], 'uz')], $this->sent());
    }

    public function test_non_numeric_file_size_is_ignored(): void
    {
        $this->fakeApis();
        $this->send(['file_size' => 'big']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame('🎙 Zebra printerining narxi qancha boʻladi', $this->sent()[0]);
    }

    public function test_download_failure_tells_the_customer(): void
    {
        $this->fakeApis(getFileOk: false);
        $this->send();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_voice_failed', [], 'uz')], $this->sent());
        $this->assertTrue(ChatConversation::sole()->awaiting_reply, 'an unanswered voice keeps the chat waiting');
    }

    public function test_voice_during_registration_is_a_name_step(): void
    {
        $this->fakeApis();
        TelegramUser::where('telegram_id', '555')->update(['step' => TelegramUser::STEP_NAME, 'registered_at' => null, 'name' => null]);

        $this->send();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
        $this->assertSame([__('chat.tg_name_invalid', [], 'uz')], $this->sent());
        $this->assertTrue(ChatConversation::sole()->messages[0]['aside']);
    }

    public function test_record_only_mode_keeps_voice_as_a_waiting_message(): void
    {
        config(['services.telegram.replies' => false]);
        $this->fakeApis();
        $this->send();

        $this->assertSame([], $this->sent());
        $c = ChatConversation::sole();
        $this->assertArrayNotHasKey('content', $c->messages[0]);
        $this->assertTrue($c->awaiting_reply);
    }

    public function test_transcribed_voice_in_waiting_list_client_requests_and_mcp(): void
    {
        $this->fakeApis();
        $this->send();

        $c = ChatConversation::sole();
        $this->assertFalse($c->awaiting_reply, 'answered by the assistant');
        $this->assertCount(2, $c->textMessages());
        $this->assertSame('Zebra printerining narxi qancha boʻladi', $c->textMessages()[0]['content']);

        $request = ClientRequest::sole();
        $this->assertSame('Zebra printerining narxi qancha boʻladi', $request->question);
        $this->assertSame('telegram', $request->channel);
        $this->assertSame('answered', $request->status);

        // MCP team tool: the transcript is returned as the message text.
        McpSetting::current()->forceFill(['require_token' => true])->save();
        $out = app(TeamTools::class)->call('get_conversation', ['id' => $c->id]);
        $this->assertSame('Zebra printerining narxi qancha boʻladi', $out['messages'][0]['text']);

        // A staff member sees the transcript on the user and waiting pages.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.telegram-users.show', TelegramUser::sole()))->assertOk()
            ->assertSee('Transcript:')->assertSee('Zebra printerining narxi qancha boʻladi')->assertDontSee('Not answered yet');
    }

    public function test_awaiting_list_shows_transcript_when_answer_is_pending(): void
    {
        $this->fakeApis();
        $this->send();
        $c = ChatConversation::sole();
        // A later voice that failed keeps the chat waiting.
        $messages = $c->messages;
        $messages[] = ['role' => 'user', 'type' => 'voice', 'file_id' => 'v9', 'content' => 'Yetkazib berish bormi', 'transcribed' => true, 'message_id' => 500, 'at' => now()->toIso8601String()];
        $c->messages = $messages;
        $c->save();

        $this->assertTrue($c->fresh()->awaiting_reply);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get(route('admin.awaiting-replies.index'))->assertOk()->assertSee('Yetkazib berish bormi');
    }

    public function test_daily_counter_with_the_database_cache_store(): void
    {
        config(['cache.default' => 'database', 'services.groq.voice_daily_limit' => 2]);
        Cache::flush();
        $this->fakeApis();

        $this->send();
        $this->send();
        $this->send();

        $this->assertSame(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'groq.com'))->count());
        $this->assertSame(__('chat.tg_voice_limit', [], 'uz'), last($this->sent()));
        $this->assertSame(3, (int) Cache::get('telegram-voice:day:'.now()->toDateString()));
    }

    public function test_long_cyrillic_transcript_is_cut_for_the_ai(): void
    {
        $long = str_repeat('Сколько стоит принтер ', 100); // ~2200 chars
        $this->fakeApis(transcript: $long);
        $this->send();

        $m = ChatConversation::sole()->messages[0];
        $this->assertSame(1000, mb_strlen($m['content']));
        $this->assertTrue($m['transcribed']);
        $this->assertLessThanOrEqual(4000, mb_strlen($this->sent()[0]));
    }
}
