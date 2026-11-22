<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\InvoiceParserSetting;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\User;
use App\Services\SalesAssistant\SalesAssistantAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One Telegram customer = one conversation, whether they write to the bot or to the Premium account (`via` per message). */
class MergedTelegramConversationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET, 'services.telegram.chat_id' => '-100555',
            'services.telegram.replies' => false, 'services.anthropic.key' => 'sk-ant-test']);
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $n = 900;
        Http::fake(['api.telegram.org/*' => function () use (&$n) {
            return Http::response(['ok' => true, 'result' => ['message_id' => $n++]]);
        }]);
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private MergedTelegramConversationTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode(['reply' => 'Qaysi oʻlcham kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'questions',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [['product' => 'Labels', 'details' => '']], 'summary' => 'Labels.', 'confirmed' => false,
                    'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false]), 'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
        Carbon::setTestNow(Carbon::parse('2026-11-23 20:00', 'Asia/Tashkent')); // outside working hours: answered at once
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bot(int $update, int $messageId, string $text, bool $edited = false): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $update, ($edited ? 'edited_message' : 'message') => ['text' => $text, 'message_id' => $messageId, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function premium(int $update, int $messageId, string $text, bool $edited = false, int $from = 7001): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $update, ($edited ? 'edited_business_message' : 'business_message') => ['text' => $text, 'message_id' => $messageId,
            'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7001, 'type' => 'private', 'first_name' => 'Ali'], 'from' => ['id' => $from, 'first_name' => $from === 7001 ? 'Ali' : 'Aziz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** @return list<array> */
    private function sends(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    public function test_both_chats_are_one_conversation_and_each_answer_goes_where_the_customer_wrote(): void
    {
        $this->bot(1, 5, 'Salom, etiketka kerak');
        $this->premium(2, 5, 'Va lenta ham'); // the same message_id in the other chat: a different message

        $c = ChatConversation::sole();
        $this->assertSame('telegram', $c->channel);
        $this->assertSame(['bot', 'bot', 'assistant', 'assistant'], array_map(fn ($m) => $c->viaOf($m), $c->messages));
        $this->assertSame([null, null, 'bc1', 'bc1'], array_map(fn ($m) => $m['connection'] ?? null, $c->messages));
        $this->assertSame('assistant', $c->via());

        [$first, $second] = $this->sends();
        $this->assertArrayNotHasKey('business_connection_id', $first, 'the bot-chat message is answered by the bot');
        $this->assertSame('bc1', $second['business_connection_id'] ?? null, 'the Premium-chat message is answered as the account');
        $this->assertStringContainsString('"chat":"bot"', $this->calls[1], 'Claude sees which chat each line came from');
        $this->assertStringContainsString('"chat":"premium account"', $this->calls[1]);

        // Edits and deletions find the right message although both have message_id 5.
        $this->bot(3, 5, 'Salom, etiketka kerak 58x40', edited: true);
        $this->premium(4, 5, 'Va lenta ham, 110 mm', edited: true);
        $this->postJson('/telegram/webhook', ['update_id' => 5, 'deleted_business_messages' => ['business_connection_id' => 'bc1',
            'chat' => ['id' => 7001, 'type' => 'private'], 'message_ids' => [5]]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $messages = ChatConversation::sole()->messages;
        $this->assertSame('Salom, etiketka kerak 58x40', $messages[0]['content']);
        $this->assertArrayNotHasKey('deleted', $messages[0]);
        $this->assertSame('Va lenta ham, 110 mm', $messages[2]['content']);
        $this->assertTrue($messages[2]['deleted']);
        $this->assertSame(4, count($messages));
    }

    public function test_staff_reply_goes_through_the_chat_the_customer_used_last(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        SalesAssistantSetting::current()->update(['bot_mode' => 'off', 'business_mode' => 'off']);
        $this->bot(1, 5, 'Salom');
        $this->premium(2, 6, 'Skaner bormi?');
        $c = ChatConversation::sole();
        $this->assertTrue($c->awaiting_reply);

        $this->actingAs($admin)->get(route('admin.chat-conversations.show', $c))->assertOk()
            ->assertSee('via Premium account')->assertSee('it is sent there, on behalf of the account');
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Ha, bor'])->assertRedirect();
        $this->assertSame('bc1', $this->sends()[0]['business_connection_id'] ?? null);
        $last = collect(ChatConversation::sole()->messages)->last();
        $this->assertSame(['admin', 'assistant', $admin->name], [$last['role'], $last['via'], $last['author']]);
        $this->assertFalse(ChatConversation::sole()->awaiting_reply);

        $this->bot(3, 7, 'Rahmat');
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Arzimaydi'])->assertRedirect();
        $this->assertArrayNotHasKey('business_connection_id', $this->sends()[1], 'back in the bot chat: sent by the bot');

        TelegramBusinessConnection::where('connection_id', 'bc1')->update(['can_reply' => false]);
        $this->premium(4, 8, 'Yana savol');
        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Javob'])->assertSessionHas('telegram_error');
        $this->assertCount(2, $this->sends());
    }

    public function test_an_older_premium_session_and_the_new_conversation_are_one_chat_in_the_admin(): void
    {
        $old = ChatConversation::create(['uuid' => 'u-old', 'channel' => 'business', 'external_id' => '7001', 'business_connection_id' => 'bc1', 'locale' => 'uz',
            'messages' => [['role' => 'user', 'content' => 'Eski savol', 'at' => now()->subDays(2)->toIso8601String()]], 'message_count' => 1]);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
        SalesAssistantSetting::current()->update(['bot_mode' => 'off', 'business_mode' => 'off']);
        $this->bot(1, 5, 'Yangi savol');

        $this->assertSame(1, ChatConversation::awaitingReply()->count(), 'one customer, one entry on Waiting for reply');
        $new = ChatConversation::where('channel', 'telegram')->sole();
        $this->assertTrue($old->latestSession()->is($new));
        $history = $new->history(7)['messages'];
        $this->assertSame(['Eski savol', 'Yangi savol'], array_column($history, 'content'));
        $this->assertSame(['assistant', 'bot'], array_column($history, 'via'));
    }
}
