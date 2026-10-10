<?php

namespace Tests\Feature;

use App\Models\AiInstruction;
use App\Models\User;
use App\Services\ChatAssistant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiMainPromptTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function preview(string $channel = 'web'): string
    {
        return app(ChatAssistant::class)->promptPreview('ru', $channel);
    }

    public function test_migration_stores_the_default_main_prompt(): void
    {
        $main = AiInstruction::where('kind', AiInstruction::MAIN)->sole();

        $this->assertSame(ChatAssistant::DEFAULT_PROMPT, $main->content);
        $this->assertTrue($main->is_active);
        $this->assertSame($main->id, AiInstruction::main()->id, 'main() reuses the row');
    }

    public function test_prompt_starts_with_the_stored_main_prompt_and_keeps_the_reply_format(): void
    {
        $prompt = $this->preview();

        $this->assertStringStartsWith('You are Belgi', $prompt);
        $this->assertStringContainsString('STRICT RULE', $prompt);
        $this->assertStringContainsString('REPLY FORMAT', $prompt);
        $this->assertStringContainsString('Reply in Russian', $prompt);
        $this->assertStringContainsString('topic:', $prompt);
        $this->assertStringContainsString('WEBSITE DATA', $prompt);
    }

    public function test_admin_edits_the_main_prompt_and_the_ai_uses_it_at_once(): void
    {
        $main = AiInstruction::main();
        $this->preview(); // fill the cache

        $this->actingAs($this->admin())->put(route('admin.ai-instructions.update', $main), [
            'content' => "You are Belgi from BDB. CUSTOM-MAIN-PROMPT\n- Only use website data.",
            // Ignored for the main prompt:
            'kind' => 'rules', 'channel' => 'web', 'title' => 'Hacked',
        ])->assertRedirect(route('admin.ai-instructions.index'));

        $main->refresh();
        $this->assertSame(AiInstruction::MAIN, $main->kind);
        $this->assertSame('all', $main->channel);
        $this->assertSame('Main prompt', $main->title);

        foreach (['web', 'telegram'] as $channel) {
            $prompt = $this->preview($channel);
            $this->assertStringStartsWith('You are Belgi from BDB. CUSTOM-MAIN-PROMPT', $prompt);
            $this->assertStringNotContainsString(ChatAssistant::DEFAULT_PROMPT, $prompt);
            $this->assertStringContainsString('REPLY FORMAT', $prompt, 'the technical part is always added');
        }
    }

    public function test_main_prompt_is_not_used_as_a_rules_document(): void
    {
        $prompt = $this->preview();

        $this->assertSame(1, substr_count($prompt, 'You are Belgi'));
        $this->assertStringNotContainsString('### Main prompt', $prompt);
    }

    public function test_empty_or_missing_main_prompt_falls_back_to_the_default(): void
    {
        AiInstruction::main()->update(['content' => '   ']);
        $this->assertStringStartsWith(ChatAssistant::DEFAULT_PROMPT, $this->preview());

        AiInstruction::main()->delete();
        $this->assertStringStartsWith(ChatAssistant::DEFAULT_PROMPT, $this->preview());
    }

    public function test_main_prompt_cannot_be_deleted_or_emptied_and_can_be_reset(): void
    {
        $main = AiInstruction::main();
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('admin.ai-instructions.index'))
            ->delete(route('admin.ai-instructions.destroy', $main))->assertSessionHasErrors('content');
        $this->assertNotNull($main->fresh());

        $this->actingAs($admin)->put(route('admin.ai-instructions.update', $main), ['content' => ''])
            ->assertSessionHasErrors('content');

        $main->update(['content' => 'edited']);
        $this->actingAs($admin)->post(route('admin.ai-instructions.reset'))
            ->assertRedirect(route('admin.ai-instructions.edit', $main));
        $this->assertSame(ChatAssistant::DEFAULT_PROMPT, $main->fresh()->content);
    }

    public function test_admin_pages_show_the_main_prompt(): void
    {
        $admin = $this->admin();
        $main = AiInstruction::main();

        $this->actingAs($admin)->get(route('admin.ai-instructions.index'))
            ->assertOk()->assertSee('Edit main prompt')->assertSee('default text');

        $this->actingAs($admin)->get(route('admin.ai-instructions.edit', $main))
            ->assertOk()->assertSee('Keep the', false)->assertSee('Reset to default')
            ->assertDontSee('name="kind"', false)->assertDontSee('name="title"', false);

        $this->post(route('admin.ai-instructions.reset'))->assertRedirect(route('admin.ai-instructions.edit', $main));
        auth()->logout();
        $this->post(route('admin.ai-instructions.reset'))->assertRedirect(route('admin.login'));
    }
}
