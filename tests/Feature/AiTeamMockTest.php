<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AiTeamController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AI Sales & Support Team mock-up pages (training, client portraits): they render, are admin-only and save nothing. */
class AiTeamMockTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mock_page_renders_for_admins_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (AiTeamController::PAGES as $page) {
            $this->get(route('admin.ai-team.show', $page))->assertRedirect();
            $this->actingAs($admin)->get(route('admin.ai-team.show', $page))->assertOk()
                ->assertSee('Mock-up.')->assertSee('AI Sales &amp; Support Team', false)->assertSee('aria-current="page"', false);
            auth()->logout();
        }
        $this->actingAs($admin)->get('/admin/ai-team/nonsense')->assertNotFound();
        $this->actingAs($admin)->get(route('admin.ai-team.show', 'test-chat'))->assertSee('Malika — Sales consultant');
        $this->actingAs($admin)->get(route('admin.ai-team.show', 'clients'))->assertOk()
            ->assertSee('Oila Market')->assertSee('Left the company')->assertSee('Now at Sifat Pharm');
    }
}
