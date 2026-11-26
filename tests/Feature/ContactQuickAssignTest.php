<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ExpertQuestion;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Contacts list: type, employee role, "answers AI questions" and the company set on the row itself. */
class ContactQuickAssignTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function contact(array $a = []): TelegramUser
    {
        return TelegramUser::create($a + ['telegram_id' => (string) random_int(1000, 999999), 'profile_name' => 'Ali', 'notes' => 'keep me', 'ask_registration' => true]);
    }

    public function test_list_shows_the_controls_and_company_suggestions(): void
    {
        Client::create(['name' => 'Korzinka Demo', 'status' => 'active']);
        $c = $this->contact();
        $this->actingAs($this->admin())->get(route('admin.telegram-users.index'))->assertOk()
            ->assertSee('Contacts')->assertSee(route('admin.telegram-users.assign', $c), false)
            ->assertSee('name="contact_type"', false)->assertSee('Answers AI questions')->assertSee('<option value="Korzinka Demo">', false);
    }

    public function test_assign_employee_with_role_expert_and_company(): void
    {
        $c = $this->contact();
        $this->actingAs($this->admin())->from(route('admin.telegram-users.index'))
            ->put(route('admin.telegram-users.assign', $c), ['contact_type' => 'employee', 'employee_role' => 'Service', 'answers_questions' => '1', 'company' => ' BDB '])
            ->assertRedirect(route('admin.telegram-users.index'))->assertSessionHas('success', fn ($m) => str_contains($m, '/start'));

        $c->refresh();
        $this->assertSame('employee', $c->contact_type);
        $this->assertTrue($c->is_employee);
        $this->assertSame('Service', $c->employee_role);
        $this->assertTrue($c->answers_questions);
        $this->assertSame('BDB', $c->company);
        $this->assertNotNull($c->assigned_at);
        // Nothing else on the card changes.
        $this->assertSame('keep me', $c->notes);
        $this->assertTrue($c->ask_registration);
    }

    public function test_client_drops_employee_fields_and_main_admin(): void
    {
        $c = $this->contact(['contact_type' => 'employee', 'is_employee' => true, 'employee_role' => 'CEO', 'answers_questions' => true, 'is_main_admin' => true]);
        $q = ExpertQuestion::create(['question' => 'Q?', 'status' => 'asked', 'expert_id' => $c->id, 'asked_at' => now()]);

        $this->actingAs($this->admin())->put(route('admin.telegram-users.assign', $c), ['contact_type' => 'client', 'employee_role' => 'CEO', 'answers_questions' => '1', 'company' => ''])->assertRedirect();

        $c->refresh();
        $this->assertSame('client', $c->contact_type);
        $this->assertFalse($c->is_employee);
        $this->assertNull($c->employee_role);
        $this->assertFalse($c->answers_questions);
        $this->assertFalse($c->is_main_admin);
        $this->assertNull($c->company);
        $this->assertSame('queued', $q->refresh()->status); // their open question goes back to the queue
    }

    public function test_bad_type_or_role_is_refused(): void
    {
        $c = $this->contact();
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('admin.telegram-users.assign', $c), ['contact_type' => 'boss'])->assertSessionHasErrors('contact_type');
        $this->actingAs($admin)->put(route('admin.telegram-users.assign', $c), ['contact_type' => 'employee', 'employee_role' => 'Astronaut'])->assertSessionHasErrors('employee_role');
        $this->assertNull($c->refresh()->contact_type);
    }

    public function test_unassigning_clears_the_date_and_guests_cannot_assign(): void
    {
        $c = $this->contact(['contact_type' => 'supplier', 'assigned_at' => now()]);
        $this->put(route('admin.telegram-users.assign', $c), ['contact_type' => 'client'])->assertRedirect(route('admin.login'));
        $this->assertSame('supplier', $c->refresh()->contact_type);

        $this->actingAs($this->admin())->put(route('admin.telegram-users.assign', $c), ['contact_type' => ''])->assertRedirect();
        $this->assertNull($c->refresh()->contact_type);
        $this->assertNull($c->assigned_at);
    }
}
