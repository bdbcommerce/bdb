<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\ChatConversation;
use App\Models\ClientRequest;
use App\Models\KnowledgeEntry;
use App\Models\McpSetting;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** MCP team tools: Claude reads conversations and teaches the chat assistant (token only). */
class McpTeamToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = McpSetting::current();
        $settings->update(['enabled' => true, 'require_token' => true]);
        $this->token = $settings->regenerateToken();
    }

    private function tool(string $name, array $arguments = [])
    {
        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
            ['Authorization' => 'Bearer '.$this->token])->assertOk();
    }

    private function conversation(): ChatConversation
    {
        TelegramUser::create(['telegram_id' => '555', 'name' => 'Aziz', 'phone' => '+998901234567']);

        return ChatConversation::create([
            'uuid' => (string) Str::uuid(), 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'messages' => [
                ['role' => 'user', 'content' => '/start', 'aside' => true],
                ['role' => 'user', 'content' => 'Сколько стоит ремонт принтера Zebra?'],
                ['role' => 'assistant', 'content' => 'Нет данных.', 'answered' => false],
                ['role' => 'user', 'type' => 'photo', 'file_id' => 'AgAC', 'caption' => 'Вот он'],
            ],
            'message_count' => 4, 'unanswered_count' => 1,
        ]);
    }

    public function test_team_tools_need_token_mode(): void
    {
        $names = fn () => collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$this->token])->json('result.tools'))->pluck('name');
        $this->assertTrue($names()->contains('get_conversation'));
        $this->assertTrue($names()->contains('save_knowledge_entry'));

        McpSetting::current()->update(['require_token' => false]);
        $this->assertFalse($names()->contains('get_conversation')); // never on a public server
        $this->assertFalse($names()->contains('list_knowledge'));
    }

    public function test_reads_conversations_without_phone_numbers(): void
    {
        $conversation = $this->conversation();

        $list = $this->tool('list_conversations', ['filter' => 'unanswered_by_ai'])->json('result.structuredContent.conversations');
        $this->assertSame([$conversation->id, 'telegram', 'Aziz'], [$list[0]['id'], $list[0]['channel'], $list[0]['customer']]);

        $read = $this->tool('get_conversation', ['id' => $conversation->id]);
        $messages = $read->json('result.structuredContent.messages');
        $this->assertTrue($messages[0]['bot_step']);
        $this->assertSame('Сколько стоит ремонт принтера Zebra?', $messages[1]['text']);
        $this->assertTrue($messages[2]['ai_had_no_answer']);
        $this->assertSame('photo: Вот он', $messages[3]['attachment']);
        $this->assertStringNotContainsString('998901234567', $read->getContent());
        $this->assertTrue($this->tool('get_conversation', ['id' => 999])->json('result.isError'));
    }

    public function test_teach_flow_open_question_to_knowledge_to_resolved(): void
    {
        $conversation = $this->conversation();
        ClientRequest::create(['chat_conversation_id' => $conversation->id, 'channel' => 'telegram', 'external_id' => '555', 'locale' => 'ru',
            'question' => 'Сколько стоит ремонт принтера Zebra?', 'status' => 'open', 'topic' => 'printer repair price']);

        $open = $this->tool('list_open_questions')->json('result.structuredContent.open_questions');
        $this->assertSame(['printer repair price', 1], [$open[0]['topic'], $open[0]['times_asked']]);

        $entry = [
            'type' => 'support',
            'question' => ['uz' => 'Printer taʼmiri qancha turadi?', 'ru' => 'Сколько стоит ремонт принтера?', 'en' => 'How much is printer repair?'],
            'answer' => ['uz' => 'Diagnostika bepul.', 'ru' => 'Диагностика бесплатна.', 'en' => 'Diagnostics are free.'],
            'keywords' => 'ремонт, repair',
        ];
        $saved = $this->tool('save_knowledge_entry', $entry)->json('result.structuredContent');
        $this->assertFalse($saved['active']); // draft by default
        $draft = KnowledgeEntry::find($saved['id']);
        $this->assertSame('Диагностика бесплатна.', $draft->getTranslation('answer', 'ru'));

        // Drafts can't resolve questions; an admin activates first.
        $this->assertTrue($this->tool('resolve_open_questions', ['request_id' => $open[0]['request_id'], 'knowledge_id' => $draft->id])->json('result.isError'));
        $draft->update(['is_active' => true]);
        $r = $this->tool('resolve_open_questions', ['request_id' => $open[0]['request_id'], 'knowledge_id' => $draft->id]);
        $this->assertSame(1, $r->json('result.structuredContent.resolved'), $r->getContent());
        $this->assertSame('resolved', ClientRequest::sole()->status);

        // Update keeps the active state; validation errors are tool errors.
        $this->tool('save_knowledge_entry', ['id' => $draft->id] + $entry);
        $this->assertTrue($draft->fresh()->is_active);
        $this->assertTrue($this->tool('save_knowledge_entry', ['answer' => ['ru' => 'x']] + $entry)->json('result.isError'));
        $this->assertTrue($this->tool('save_knowledge_entry', ['answer' => ['uz' => '[TO FILL: price]', 'ru' => 'a', 'en' => 'b']] + $entry)->json('result.isError'));
        $this->assertTrue($this->tool('save_knowledge_entry', ['type' => 'secret'] + $entry)->json('result.isError'));

        $this->assertSame($draft->id, $this->tool('list_knowledge', ['query' => 'диагностика'])->json('result.structuredContent.entries.0.id'));
    }

    public function test_ai_notes_and_autopublish(): void
    {
        $note = $this->tool('add_ai_note', ['kind' => 'rules', 'title' => 'Prices', 'content' => 'Never promise prices; offer a call back.'])->json('result.structuredContent');
        $this->assertFalse($note['active']);
        $this->assertSame('rules', AiInstruction::find($note['id'])->kind);

        McpSetting::current()->update(['autopublish' => true]);
        $this->assertTrue($this->tool('add_ai_note', ['kind' => 'info', 'channel' => 'telegram', 'title' => 'Hours', 'content' => 'Mo–Fr 9–18'])->json('result.structuredContent.active'));
        $this->assertTrue($this->tool('add_ai_note', ['kind' => 'bad', 'title' => 'x', 'content' => 'y'])->json('result.isError'));

        $this->assertContains('Prices', array_column($this->tool('get_ai_rules')->json('result.structuredContent.documents'), 'title'));
    }
}
