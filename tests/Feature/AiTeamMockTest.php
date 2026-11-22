<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AiTeamController;
use App\Models\User;
use App\Support\AiTeamMock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AI Sales & Support Team mock-up pages: they render, are admin-only and save nothing. */
class AiTeamMockTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mock_page_renders_for_admins_only(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (AiTeamController::PAGES as $page) {
            $this->get(route('admin.ai-team.show', $page))->assertRedirect();
            $this->actingAs($admin)->get(route('admin.ai-team.show', $page))->assertOk()
                ->assertSee('Mock-up.')->assertSee('AI Sales &amp; Support Team', false);
            auth()->logout();
        }
        $this->actingAs($admin)->get(route('admin.ai-team.show'))->assertOk()
            ->assertSee('Malika')->assertSee('Rustam')->assertSee('Platform rules')->assertSee('aria-current="page"', false);

        foreach (AiTeamMock::employees() as $e) {
            $this->actingAs($admin)->get(route('admin.ai-team.employee', $e['slug']))->assertOk()
                ->assertSee($e['name'])->assertSee('Temperament')->assertSee('Prompt versions');
        }
        $this->actingAs($admin)->get('/admin/ai-team/employees/nobody')->assertNotFound();
        $this->actingAs($admin)->get('/admin/ai-team/nonsense')->assertNotFound();

        $this->actingAs($admin)->get(route('admin.ai-team.show', 'clients'))->assertOk()
            ->assertSee('Oila Market')->assertSee('Left the company')->assertSee('Now at Sifat Pharm');
    }
}
