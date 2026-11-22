<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatLead;
use App\Models\ClientRequest;
use App\Models\InvoiceParserSetting;
use App\Models\KnowledgeEntry;
use App\Models\SalesAssistantSetting;
use App\Models\TelegramBusinessConnection;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\ClientRequestResolver;
use App\Services\SalesAssistant\SalesAssistantAi;
use App\Services\SalesReport\SalesReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Logic review of the merge "bot chat + Premium-account chat = one ChatConversation (channel telegram, `via` per message)":
 * places where other features still assume one chat per conversation. Each test fails while its conflict is open.
 */
class MergedConversationLogicTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'logic-secret';

    public array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.webhook_secret' => self::SECRET,
            'services.telegram.chat_id' => '-100555', 'services.gemini.key' => 'test-key', 'services.anthropic.key' => 'sk-ant-test']);
        TelegramBusinessConnection::create(['connection_id' => 'bc1', 'user_id' => '9000', 'user_chat_id' => '9000', 'name' => 'Aziz', 'is_enabled' => true, 'can_reply' => true]);
        $n = 900;
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'reply' => 'Asl Belgi — milliy markirovka tizimi.', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
            ])]]]]]]),
            'api.telegram.org/*' => function () use (&$n) {
                return Http::response(['ok' => true, 'result' => ['message_id' => $n++]]);
            },
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-23 20:00', 'Asia/Tashkent')); // outside working hours
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeClaude(): void
    {
        $test = $this;
        $this->app->instance(SalesAssistantAi::class, new class($test) extends SalesAssistantAi
        {
            public function __construct(private MergedConversationLogicTest $test) {}

            public function turn(string $apiKey, string $model, string $system, string $data, array $images, array $schema): array
            {
                $this->test->calls[] = $data;

                return ['text' => json_encode(['reply' => 'Salom! Nima kerak?', 'language' => 'uz', 'ask_phone_button' => false, 'stage' => 'need',
                    'customer' => ['first_name' => '', 'last_name' => '', 'phone' => '', 'city' => '', 'company' => ''], 'business_type' => '', 'software' => '',
                    'items' => [], 'summary' => '', 'confirmed' => false, 'unknown_question' => '', 'cancelled' => false, 'follow_up_agreed' => false]),
                    'model' => $model, 'input_tokens' => 1, 'output_tokens' => 1];
            }
        });
    }

    private function registered(): TelegramUser
    {
        return TelegramUser::create(['telegram_id' => '7001', 'locale' => 'uz', 'step' => TelegramUser::STEP_DONE, 'name' => 'Ali',
            'phone' => '+998901234567', 'registered_at' => now(), 'phone_verified_at' => now()]);
    }

    private function bot(int $update, int $messageId, string $text): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $update, 'message' => ['text' => $text, 'message_id' => $messageId, 'date' => now()->getTimestamp(),
            'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ali']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function premium(int $update, int $messageId, string $text, int $from = 7001, bool $edited = false): void
    {
        $this->postJson('/telegram/webhook', ['update_id' => $update, ($edited ? 'edited_business_message' : 'business_message') => ['text' => $text,
            'message_id' => $messageId, 'date' => now()->getTimestamp(), 'business_connection_id' => 'bc1',
            'chat' => ['id' => 7001, 'type' => 'private', 'first_name' => 'Ali'], 'from' => ['id' => $from, 'first_name' => $from === 7001 ? 'Ali' : 'Aziz']]],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /**
     * Conflict 1: the bot's own Gemini answers (sales assistant off, replies on) now read textMessages() of the merged
     * conversation — the owner's private Premium-account messages reach Gemini, labelled as the bot's own answers.
     */
    public function test_gemini_history_of_the_bot_chat_does_not_include_premium_account_messages(): void
    {
        $this->registered();
        $this->premium(1, 10, 'Ertaga ofisga kelaman');
        $this->premium(2, 11, 'Xop, kalitni qoshnida qoldiraman', from: 9000); // the owner, privately
        $this->bot(3, 5, 'Asl Belgi tizimi qanday ishlaydi va narxi qancha?');

        $gemini = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->map(fn ($p) => $p[0]->body())->implode("\n");
        $this->assertNotSame('', $gemini, 'the bot answered with Gemini');
        $this->assertStringNotContainsString('kalitni qoshnida', $gemini, 'the Premium-account owner\'s message was sent to Gemini as the bot\'s own reply');
    }

    /**
     * Conflict 2: a Premium-account question waits for the owner; an AI answer in the bot chat (another question)
     * is now the last message of the same conversation and takes the chat off "Waiting for reply".
     */
    public function test_a_bot_ai_answer_does_not_clear_a_waiting_premium_account_question(): void
    {
        $this->registered();
        $this->premium(1, 10, 'Skaner Zebra DS2208 bormi?');
        $this->assertTrue(ChatConversation::sole()->awaiting_reply);

        $this->bot(2, 5, 'Asl Belgi tizimi qanday ishlaydi va narxi qancha?');

        $this->assertTrue(ChatConversation::sole()->awaiting_reply, 'the owner never answered the Premium-account question');
        $this->assertSame(1, ChatConversation::awaitingReply()->count());
    }

    /**
     * Conflict 3: business_mode off ("leave Premium-account chats to the managers"), bot_mode on. A Premium-account
     * question is fed to the assistant on the next bot-chat message, and its bot-chat reply clears the Premium wait.
     */
    public function test_business_mode_off_keeps_premium_messages_away_from_the_bot_assistant(): void
    {
        $this->fakeClaude();
        InvoiceParserSetting::current()->update(['source_chat_id' => '-1003333333333']);
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'off']);
        $this->premium(1, 10, 'Skaner Zebra DS2208 narxi qancha?');
        $this->assertSame([], $this->calls, 'business_mode off: no turn for the Premium message');

        $this->bot(2, 5, 'Salom');

        $this->assertNotEmpty($this->calls);
        $this->assertStringNotContainsString('DS2208', $this->calls[0], 'business_mode off, yet the Premium-account message went to Claude');
        $this->assertTrue(ChatConversation::sole()->awaiting_reply, 'the Premium-account question still waits for a manager');
    }

    /**
     * Conflict 4: the sales report setting "Telegram Business (Premium account chats)" now finds nothing — Premium
     * messages are stored in channel telegram.
     */
    public function test_sales_report_with_only_the_business_channel_still_sees_premium_account_chats(): void
    {
        $this->premium(1, 10, 'Termoprinter kerak');
        $this->premium(2, 11, 'Qaysi model?', from: 9000);

        $data = app(SalesReporter::class)->collect(now()->subDay(), now()->addHour(), ['business']);

        $this->assertCount(1, $data['chats'], 'Premium-account chat missing from a report limited to Telegram Business');
    }

    /**
     * Conflict 5: the sales tick still groups by channel|external_id|business_connection_id, so an older Premium session
     * (channel business) is treated as its own "latest session": it is closed as an unfinished request (lead + sales
     * chat post) while the customer goes on talking in the merged conversation.
     */
    public function test_tick_does_not_close_an_older_premium_session_the_customer_continued_in_the_merged_chat(): void
    {
        SalesAssistantSetting::current()->update(['bot_mode' => 'on', 'business_mode' => 'on']);
        TelegramUser::create(['telegram_id' => '7001', 'locale' => 'uz', 'step' => TelegramUser::STEP_GUEST]);
        $legacy = ChatConversation::create(['uuid' => 'legacy', 'locale' => 'uz', 'channel' => 'business', 'external_id' => '7001', 'business_connection_id' => 'bc1',
            'messages' => [
                ['role' => 'user', 'content' => 'Etiketka kerak', 'message_id' => 1, 'at' => now()->subHours(30)->toIso8601String()],
                ['role' => 'assistant', 'content' => 'Qaysi oʻlcham?', 'message_id' => 2, 'at' => now()->subHours(25)->toIso8601String()],
            ], 'message_count' => 2,
            'sales_state' => ['stage' => 'need', 'summary' => 'Labels.', 'items' => [['product' => 'Labels', 'details' => '']], 'handled' => 2]]);
        ChatConversation::whereKey($legacy->id)->update(['updated_at' => now()->subHours(25)]);
        ChatConversation::create(['uuid' => 'merged', 'locale' => 'uz', 'channel' => 'telegram', 'external_id' => '7001', 'messages' => [
            ['role' => 'user', 'content' => '58x40, 10 rulon', 'message_id' => 3, 'at' => now()->subHour()->toIso8601String(), 'via' => 'assistant', 'connection' => 'bc1'],
            ['role' => 'assistant', 'content' => 'Telefon raqamingiz?', 'message_id' => 4, 'at' => now()->subHour()->toIso8601String(), 'via' => 'assistant', 'connection' => 'bc1'],
        ], 'message_count' => 2, 'sales_state' => ['stage' => 'contacts', 'handled' => 2]]);

        $this->artisan('sales:assistant-tick')->assertSuccessful();

        $this->assertSame(0, ChatLead::count(), 'an "[Unfinished]" lead was made from the superseded Premium session');
        $this->assertArrayNotHasKey('closed_at', (array) $legacy->fresh()->sales_state);
    }

    /**
     * Conflict 6: right after the deploy, editing a message of a waiting Premium chat (older session, channel business)
     * creates a new telegram conversation holding only the late edit — it supersedes the waiting session, which
     * disappears from "Waiting for reply" (scopeAwaitingReply now treats both channels as one chat).
     */
    public function test_editing_a_message_of_an_older_premium_session_keeps_it_waiting(): void
    {
        ChatConversation::create(['uuid' => 'legacy', 'locale' => 'uz', 'channel' => 'business', 'external_id' => '7001', 'business_connection_id' => 'bc1',
            'messages' => [['role' => 'user', 'content' => 'Skaner bormi', 'message_id' => 20, 'at' => now()->subHour()->toIso8601String()]], 'message_count' => 1]);
        $this->assertSame(1, ChatConversation::awaitingReply()->count());

        $this->premium(1, 20, 'Skaner bormi? Zebra', edited: true);

        $this->assertSame(1, ChatConversation::awaitingReply()->count(), 'the waiting Premium chat vanished from Waiting for reply after an edit');
        $this->assertSame(1, ChatConversation::count(), 'an edit-only conversation was created');
    }

    /**
     * Conflict 7: an unknown question asked in the Premium-account chat becomes a ClientRequest with channel telegram,
     * so "notify customers" sends the answer through the bot — to someone who wrote only to the Premium account.
     */
    public function test_answer_follow_up_to_a_premium_only_customer_is_not_sent_by_the_bot(): void
    {
        $c = ChatConversation::create(['uuid' => 'm', 'channel' => 'telegram', 'external_id' => '7001', 'locale' => 'uz', 'messages' => [
            ['role' => 'user', 'content' => 'RFID metka bormi?', 'message_id' => 3, 'at' => now()->toIso8601String(), 'via' => 'assistant', 'connection' => 'bc1'],
        ], 'message_count' => 1]);
        $entry = KnowledgeEntry::create(['type' => 'product', 'question' => ['uz' => 'RFID?', 'ru' => 'RFID?', 'en' => 'RFID?'],
            'answer' => ['uz' => 'Ha, bor.', 'ru' => 'Да.', 'en' => 'Yes.'], 'is_active' => true]);
        $request = ClientRequest::record($c, 'RFID metka bormi?', ['reply' => '', 'answered' => false, 'topic' => 'rfid', 'category' => 'product']);
        $request->update(['status' => 'resolved', 'knowledge_entry_id' => $entry->id]);

        app(ClientRequestResolver::class)->notifyCustomers($entry, 'Admin');

        $botSends = Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && ! isset($r->data()['business_connection_id']));
        $this->assertCount(0, $botSends, 'the follow-up went out through the bot chat, not the Premium-account chat the customer used');
    }

    /**
     * Conflict 8: the owner started a Premium-account chat and the other side has not written yet. via() falls back to
     * "bot" (no customer message), so a staff reply from the panel goes out through the bot, which the person never started.
     */
    public function test_staff_reply_in_an_owner_started_premium_chat_goes_through_the_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->premium(1, 10, 'Assalomu alaykum, taklifimiz bor', from: 9000);
        $c = ChatConversation::sole();

        $this->actingAs($admin)->post(route('admin.chat-conversations.reply', $c), ['message' => 'Narxlar ilova qilindi']);

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values();
        $this->assertSame('bc1', $sent[0]['business_connection_id'] ?? null, 'the reply was sent by the bot into a chat that exists only with the Premium account');
    }
}
