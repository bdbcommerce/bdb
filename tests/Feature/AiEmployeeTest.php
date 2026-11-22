<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiEmployeePrompt;
use App\Models\SalesAssistantSetting;
use App\Models\User;
use App\Support\AiEmployeeTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AI employees: the three default cards, versioned role prompts, the platform rules and what the sales engine gets. */
class AiEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function malika(): AiEmployee
    {
        return AiEmployee::where('name', 'Malika')->sole();
    }

    private function card(AiEmployee $e, array $override = []): array
    {
        return $override + ['name' => $e->name, 'role' => $e->role, 'kind' => $e->kind, 'status' => $e->status, 'audience' => $e->audience,
            'channels' => $e->channels, 'scales' => $e->scales, 'traits' => implode(', ', $e->traits ?? []), 'manner' => $e->manner,
            'say' => $e->say, 'avoid' => $e->avoid, 'handover' => $e->handover];
    }

    public function test_three_default_employees_and_the_live_sales_consultant_in_the_engine_prompt(): void
    {
        $this->assertSame(['Malika', 'Javohir', 'Dilshod'], AiEmployee::orderBy('sort')->pluck('name')->all());
        $this->assertTrue($this->malika()->isLive());
        $this->assertSame(1, $this->malika()->publishedPrompt()->version);
        $this->assertSame('Malika', AiEmployee::live('sales')->name);
        $this->assertNull(AiEmployee::live('support'), 'drafts never answer customers');

        $system = SalesAssistantSetting::current()->systemPrompt();
        $this->assertStringStartsWith('You are Malika, sales consultant at BDB', $system);
        $this->assertStringContainsString('say honestly that you are BDB\'s AI assistant', $system);
        $this->assertStringContainsString("# HOW YOU TALK\nCharacter: Warm, Patient, Curious, Never pushes.\n- Formality: friendly and warm", $system);
        $this->assertStringContainsString('Never like this: "Берите эту модель', $system);
        $this->assertStringContainsString("# YOUR ROLE\nYour role: help a new customer", $system);
        $this->assertStringContainsString("# SALES PLAYBOOK (the owner's rules for the sales conversation)\n".trim(SalesAssistantSetting::defaultPrompt()), $system);
        $this->assertStringContainsString('# KNOWLEDGE', $system);
        $this->assertStringNotContainsString('Javohir', $system);

        // Switched to Draft: the playbook alone, as before.
        $this->malika()->update(['status' => 'draft']);
        $this->assertStringStartsWith(trim(SalesAssistantSetting::defaultPrompt()), SalesAssistantSetting::current()->systemPrompt());
    }

    public function test_draft_then_publish_archives_the_old_version_and_reaches_the_engine(): void
    {
        $admin = $this->admin();
        $m = $this->malika();
        $this->actingAs($admin)->post(route('admin.ai-employees.draft', $m), ['prompt' => 'Ask two questions about the business first.', 'note' => 'Two questions'])
            ->assertRedirect(route('admin.ai-employees.edit', $m));
        $this->assertStringNotContainsString('Ask two questions', SalesAssistantSetting::current()->systemPrompt(), 'a draft is not used');

        // Saving again updates the same draft.
        $this->actingAs($admin)->post(route('admin.ai-employees.draft', $m), ['prompt' => 'Ask two questions about the business before any advice.']);
        $this->assertSame([2, 1], $m->prompts()->pluck('version')->all());

        $this->actingAs($admin)->get(route('admin.ai-employees.edit', $m))->assertOk()
            ->assertSee('Publish v2')->assertSee('draft v2')->assertSee('before any advice');
        $this->actingAs($admin)->post(route('admin.ai-employees.publish', $m))->assertSessionHasNoErrors();
        $this->assertSame(['published', 'archived'], $m->prompts()->pluck('status')->all());
        $this->assertStringContainsString("# YOUR ROLE\nAsk two questions about the business before any advice.", SalesAssistantSetting::current()->systemPrompt());

        // Roll back: v1 copied into a new draft (v3), published again.
        $v1 = $m->prompts()->where('version', 1)->sole();
        $this->actingAs($admin)->post(route('admin.ai-employees.copy', [$m, $v1]))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.ai-employees.publish', $m));
        $this->assertSame($v1->prompt, $m->publishedPrompt()->prompt);
        $this->assertSame(3, $m->publishedPrompt()->version);

        $this->actingAs($admin)->post(route('admin.ai-employees.publish', $m))->assertSessionHasErrors('employee');
        $this->actingAs($admin)->post(route('admin.ai-employees.draft', $m), ['prompt' => 'Price: [TO FILL: price]'])->assertSessionHasErrors('prompt');
        $other = AiEmployee::where('name', 'Javohir')->sole();
        $this->actingAs($admin)->post(route('admin.ai-employees.copy', [$m, $other->prompts()->first()]))->assertNotFound();
    }

    public function test_card_edits_and_the_live_rules(): void
    {
        $admin = $this->admin();
        $m = $this->malika();
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $m), $this->card($m, ['scales' => ['formality' => 5, 'length' => 1, 'initiative' => 9, 'emoji' => 1], 'traits' => ' Calm ,, Kind ']))
            ->assertSessionHasErrors('scales.initiative');
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $m), $this->card($m, ['scales' => ['formality' => 5, 'length' => 1, 'initiative' => 2, 'emoji' => 1], 'traits' => ' Calm ,, Kind ']))
            ->assertSessionHasNoErrors();
        $m->refresh();
        $this->assertSame(['Calm', 'Kind'], $m->traits);
        $this->assertStringContainsString('- Formality: very formal', SalesAssistantSetting::current()->systemPrompt());

        // Only a sales consultant with a published prompt can be live, and one at a time.
        $j = AiEmployee::where('name', 'Javohir')->sole();
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $j), $this->card($j, ['kind' => 'account', 'status' => 'live']))->assertSessionHasErrors('status');
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $j), $this->card($j, ['kind' => 'sales', 'status' => 'live']))->assertSessionHasErrors('status');
        $this->actingAs($admin)->put(route('admin.ai-employees.update', $m), $this->card($m, ['name' => 'Mal<script>']))->assertSessionHasErrors('name');

        // A live employee cannot be deleted; a draft can.
        $this->actingAs($admin)->delete(route('admin.ai-employees.destroy', $m))->assertSessionHasErrors('employee');
        $this->actingAs($admin)->delete(route('admin.ai-employees.destroy', $j))->assertRedirect(route('admin.ai-employees.index'));
        $this->assertSame(2, AiEmployee::count());
        $this->assertSame(0, AiEmployeePrompt::where('ai_employee_id', $j->id)->count());
    }

    public function test_add_from_a_template_and_platform_rules(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.ai-employees.index'))->assertOk()
            ->assertSee('Malika')->assertSee('Javohir')->assertSee('Dilshod')->assertSee('Add Nodira')->assertSee('Add Rustam')->assertSee('Platform rules');
        $this->actingAs($admin)->get(route('admin.ai-employees.create', ['template' => 'nodira']))->assertOk()->assertSee('Account manager')->assertSee('re-order');

        $t = AiEmployeeTemplates::all()['nodira'];
        $this->actingAs($admin)->post(route('admin.ai-employees.store'), ['name' => 'Nodira', 'role' => $t['role'], 'kind' => 'account', 'status' => 'draft',
            'channels' => ['bot'], 'scales' => $t['scales'], 'traits' => 'Friendly', 'prompt' => $t['prompt']])->assertRedirect();
        $n = AiEmployee::where('name', 'Nodira')->sole();
        $this->assertSame('draft', $n->draftPrompt()->status);
        $this->assertNull($n->publishedPrompt());
        $this->actingAs($admin)->get(route('admin.ai-employees.index'))->assertDontSee('Add Nodira');

        $this->actingAs($admin)->put(route('admin.ai-team.rules'), ['platform_rules' => "You are {name} ({role}). Be kind.\n<b>x</b>"])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('You are Malika (sales consultant). Be kind.', SalesAssistantSetting::current()->systemPrompt());
        $this->actingAs($admin)->get(route('admin.ai-employees.index'))->assertSee('&lt;b&gt;x&lt;/b&gt;', false)->assertSee('Reset to default');
        $this->actingAs($admin)->put(route('admin.ai-team.rules'), ['platform_rules' => '']);
        $this->assertNull(SalesAssistantSetting::current()->platform_rules);
    }

    public function test_guests_and_non_admins_are_kept_out(): void
    {
        $m = $this->malika();
        $this->get(route('admin.ai-employees.index'))->assertRedirect();
        $this->post(route('admin.ai-employees.publish', $m))->assertRedirect();
        $user = User::factory()->create(['is_admin' => false]);
        $this->assertNotEquals(200, $this->actingAs($user)->get(route('admin.ai-employees.edit', $m))->status());
        $this->actingAs($user)->put(route('admin.ai-team.rules'), ['platform_rules' => 'x']);
        $this->assertNull(SalesAssistantSetting::current()->platform_rules);
    }
}
