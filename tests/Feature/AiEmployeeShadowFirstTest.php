<?php

namespace Tests\Feature;

use App\Models\AiEmployee;
use App\Models\AiTestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shadow first: a draft employee goes live only through Shadow, and a new prompt for a live one puts it back in Shadow. */
class AiEmployeeShadowFirstTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true]);
        AiTestCase::query()->update(['is_active' => false]); // the publish gate is tested elsewhere
    }

    private function card(AiEmployee $e, array $override = []): array
    {
        return $override + ['name' => $e->name, 'role' => $e->role, 'kind' => $e->kind, 'status' => $e->status, 'audience' => $e->audience,
            'channels' => $e->channels, 'scales' => $e->scales, 'traits' => implode(', ', $e->traits ?? []), 'manner' => $e->manner,
            'say' => $e->say, 'avoid' => $e->avoid, 'handover' => $e->handover];
    }

    public function test_draft_cannot_jump_to_live_but_can_go_through_shadow(): void
    {
        $javohir = AiEmployee::where('name', 'Javohir')->sole(); // a draft with a published prompt
        $this->assertSame('draft', $javohir->status);

        $this->actingAs($this->admin)->put(route('admin.ai-employees.update', $javohir), $this->card($javohir, ['status' => 'live']))
            ->assertSessionHasErrors(['status' => 'Shadow first: switch it to Shadow, check its drafts in AI team → Shadow replies, then set it Live.']);
        $this->assertSame('draft', $javohir->refresh()->status);

        $this->actingAs($this->admin)->put(route('admin.ai-employees.update', $javohir), $this->card($javohir, ['status' => 'shadow']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('admin.ai-employees.update', $javohir), $this->card($javohir->refresh(), ['status' => 'live']))->assertSessionHasNoErrors();
        $this->assertSame('live', $javohir->refresh()->status);
    }

    public function test_publishing_a_new_prompt_for_a_live_employee_puts_it_back_in_shadow(): void
    {
        $malika = AiEmployee::where('name', 'Malika')->sole();
        $this->assertSame('live', $malika->status);

        $this->actingAs($this->admin)->post(route('admin.ai-employees.draft', $malika), ['prompt' => 'A changed role prompt for Malika.', 'note' => 'tone'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.ai-employees.publish', $malika))
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'back in Shadow'));
        $this->assertSame('shadow', $malika->refresh()->status);
        $this->assertSame('A changed role prompt for Malika.', $malika->publishedPrompt()->prompt);
    }

    public function test_publishing_for_a_draft_or_shadow_employee_keeps_its_status(): void
    {
        $dilshod = AiEmployee::where('name', 'Dilshod')->sole();
        $this->actingAs($this->admin)->post(route('admin.ai-employees.draft', $dilshod), ['prompt' => 'New support prompt.'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('admin.ai-employees.publish', $dilshod))->assertSessionHasNoErrors();
        $this->assertSame('draft', $dilshod->refresh()->status);
    }
}
