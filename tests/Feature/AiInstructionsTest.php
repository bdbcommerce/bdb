<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\User;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function fakeGemini(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'reply' => 'ok', 'suggestions' => [], 'offer_callback' => false, 'answered' => true,
            'knowledge_id' => 0, 'topic' => 'x', 'category' => 'faq',
        ])]]]]]])]);
    }

    private function prompt(): string
    {
        return Http::recorded()->map(fn ($p) => $p[0])
            ->first(fn (Request $r) => str_contains($r->url(), 'generateContent'))['system_instruction']['parts'][0]['text'];
    }

    public function test_admin_creates_edits_and_deletes_a_markdown_document(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ai-instructions.create'))->assertOk()->assertSee('easymde', false);

        $this->actingAs($admin)->post(route('admin.ai-instructions.store'), [
            'title' => 'Tone', 'kind' => 'rules', 'channel' => 'all', 'is_active' => 1,
            'content' => "- Always greet the customer\n- **Never** promise delivery dates",
        ])->assertRedirect(route('admin.ai-instructions.index'));

        $doc = AiInstruction::where('kind', '!=', AiInstruction::MAIN)->sole();
        $this->assertTrue($doc->is_active);
        $this->assertStringContainsString('**Never**', $doc->content);

        $this->actingAs($admin)->get(route('admin.ai-instructions.index'))->assertOk()->assertSee('Tone')->assertSee('Active');

        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $doc), [
            'title' => 'Tone', 'kind' => 'info', 'channel' => 'telegram', 'content' => 'x',
        ])->assertRedirect();
        $this->assertSame('info', $doc->fresh()->kind);
        $this->assertFalse($doc->fresh()->is_active);

        $this->actingAs($admin)->delete(route('admin.ai-instructions.destroy', $doc))->assertRedirect();
        $this->assertSame(0, AiInstruction::where('kind', '!=', AiInstruction::MAIN)->count());
    }

    public function test_validation(): void
    {
        $this->actingAs($this->admin())->post(route('admin.ai-instructions.store'), [
            'title' => '', 'kind' => 'bogus', 'channel' => 'sms', 'content' => str_repeat('a', AiInstruction::MAX_LENGTH + 1),
        ])->assertSessionHasErrors(['title', 'kind', 'channel', 'content']);
    }

    public function test_pages_are_admin_only(): void
    {
        $this->get(route('admin.ai-instructions.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.ai-instructions.prompt'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.ai-instructions.store'), ['title' => 'x', 'kind' => 'rules', 'channel' => 'all', 'content' => 'x'])
            ->assertRedirect(route('admin.login'));
        $this->assertSame(0, AiInstruction::where('kind', '!=', AiInstruction::MAIN)->count());
    }

    public function test_active_rules_and_info_reach_the_ai_for_the_right_channel(): void
    {
        AiInstruction::create(['title' => 'Everywhere rule', 'kind' => 'rules', 'channel' => 'all', 'content' => 'RULE-ALL', 'is_active' => true]);
        AiInstruction::create(['title' => 'Web rule', 'kind' => 'rules', 'channel' => 'web', 'content' => 'RULE-WEB', 'is_active' => true]);
        AiInstruction::create(['title' => 'Telegram rule', 'kind' => 'rules', 'channel' => 'telegram', 'content' => 'RULE-TG', 'is_active' => true]);
        AiInstruction::create(['title' => 'Office hours', 'kind' => 'info', 'channel' => 'all', 'content' => 'INFO-HOURS 9-18', 'is_active' => true]);
        AiInstruction::create(['title' => 'Off', 'kind' => 'rules', 'channel' => 'all', 'content' => 'RULE-OFF', 'is_active' => false]);
        $this->fakeGemini();

        $this->postJson('/chat/message', ['locale' => 'ru', 'conversation' => (string) Str::uuid(),
            'messages' => [['role' => 'user', 'content' => 'Когда вы работаете?']]])->assertOk();

        $prompt = $this->prompt();
        $this->assertStringContainsString('STAFF RULES', $prompt);
        $this->assertStringContainsString("### Everywhere rule\nRULE-ALL", $prompt);
        $this->assertStringContainsString('RULE-WEB', $prompt);
        $this->assertStringNotContainsString('RULE-TG', $prompt);
        $this->assertStringNotContainsString('RULE-OFF', $prompt);
        $this->assertStringContainsString("## Additional information from BDB staff\n### Office hours\nINFO-HOURS 9-18", $prompt);

        // The strict website-data rule comes first and is not replaced.
        $this->assertLessThan(strpos($prompt, 'STAFF RULES'), strpos($prompt, 'STRICT RULE'));
    }

    public function test_saving_a_document_takes_effect_immediately(): void
    {
        $this->fakeGemini();
        $this->postJson('/chat/message', ['locale' => 'en', 'messages' => [['role' => 'user', 'content' => 'Hi']]])->assertOk();
        $this->assertStringNotContainsString('NEW-RULE', $this->prompt());

        $this->actingAs($this->admin())->post(route('admin.ai-instructions.store'), [
            'title' => 'New', 'kind' => 'rules', 'channel' => 'all', 'is_active' => 1, 'content' => 'NEW-RULE',
        ]);

        // The cached prompt was flushed on save.
        $this->assertStringContainsString('NEW-RULE', app(ChatAssistant::class)->promptPreview('en', 'web'));
    }

    public function test_prompt_preview_shows_what_the_ai_gets_per_channel(): void
    {
        AiInstruction::create(['title' => 'TG only', 'kind' => 'rules', 'channel' => 'telegram', 'content' => 'RULE-TG-<b>', 'is_active' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ai-instructions.prompt', ['channel' => 'telegram', 'locale' => 'uz']))
            ->assertOk()->assertSee('RULE-TG-&lt;b&gt;', false)->assertSee('Uzbek (Latin script)')->assertSee('At most 2 short sentences');

        $this->actingAs($admin)->get(route('admin.ai-instructions.prompt', ['channel' => 'web', 'locale' => 'zz']))
            ->assertOk()->assertDontSee('RULE-TG')->assertSee('Russian');
    }
}
