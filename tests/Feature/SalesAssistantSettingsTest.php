<?php

namespace Tests\Feature;

use App\Models\SalesAssistantSetting;
use App\Models\SalesKnowledge;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesAssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function valid(array $override = []): array
    {
        return $override + ['bot_mode' => 'test', 'business_mode' => 'off', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 2, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => 'claude-haiku-5-5', 'employee_ids' => "123456789\n@Aziz_Sales\nbad!"];
    }

    public function test_the_owners_answers_are_seeded_and_unknown_facts_are_left_empty(): void
    {
        $this->assertSame(9, SalesKnowledge::where('kind', 'product')->count());
        $this->assertSame(6, SalesKnowledge::where('kind', 'business')->count());
        $this->assertSame(0, SalesKnowledge::whereIn('kind', ['marking', 'software', 'device'])->count(), 'never invented');

        $prompt = SalesAssistantSetting::current()->systemPrompt();
        $this->assertStringContainsString('Never give a price', $prompt);
        $this->assertStringContainsString('### Labels', $prompt);
        $this->assertStringContainsString("## Asl Belgi categories\n(empty — do not state anything", $prompt);
        $this->assertStringContainsString('Mon, Tue, Wed, Thu, Fri 09:00–18:00', $prompt);
        $this->assertStringContainsString('5 minutes', $prompt);
    }

    public function test_pages_render_and_settings_save(): void
    {
        $admin = $this->admin();
        foreach (['admin.sales-assistant.edit', 'admin.sales-assistant.prompt', 'admin.sales-knowledge.index', 'admin.sales-knowledge.create'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()->assertSee('Sales assistant');
        }
        foreach (array_keys(SalesKnowledge::KINDS) as $kind) {
            $this->actingAs($admin)->get(route('admin.sales-knowledge.index', ['kind' => $kind]))->assertOk();
        }
        $this->actingAs($admin)->get(route('admin.sales-knowledge.index', ['kind' => ['x']]))->assertOk();

        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $this->valid())->assertSessionHasNoErrors();
        $s = SalesAssistantSetting::current();
        $this->assertSame(['test', [1, 2, 3, 4, 5]], [$s->bot_mode, $s->work_days]);
        $this->assertSame(['123456789', '@aziz_sales'], $s->employeeList());

        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $this->valid(['bot_mode' => 'x', 'work_days' => [], 'work_end' => '08:00', 'close_after_hours' => 1, 'model' => 'gpt']))
            ->assertSessionHasErrors(['bot_mode', 'work_days', 'work_end', 'close_after_hours', 'model']);

        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route('admin.sales-assistant.edit'))->assertStatus(302);
        $this->actingAs(User::factory()->create(['is_admin' => false]))->put(route('admin.sales-assistant.update'), $this->valid(['bot_mode' => 'on']))->assertStatus(302);
        $this->assertSame('test', SalesAssistantSetting::current()->bot_mode);
    }

    public function test_prompt_is_editable_and_the_built_in_text_is_kept_as_default(): void
    {
        $admin = $this->admin();
        $text = SalesAssistantSetting::defaultPrompt()."\n\nAlways say hello twice.";
        $this->actingAs($admin)->put(route('admin.sales-assistant.prompt.update'), ['prompt' => $text])->assertSessionHasNoErrors();
        $this->assertStringContainsString('hello twice', SalesAssistantSetting::current()->systemPrompt());

        $this->actingAs($admin)->put(route('admin.sales-assistant.prompt.update'), ['prompt' => SalesAssistantSetting::defaultPrompt()]);
        $this->assertNull(SalesAssistantSetting::current()->prompt, 'same as the file: nothing stored');
        $this->actingAs($admin)->put(route('admin.sales-assistant.prompt.update'), ['prompt' => 'short'])->assertSessionHasErrors('prompt');
    }

    public function test_knowledge_crud_and_escaping(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.sales-knowledge.store'), ['kind' => 'marking', 'title' => 'Water <b>', 'body' => "Drinking water\r\nfrom 2024", 'is_active' => '1'])
            ->assertRedirect(route('admin.sales-knowledge.index', ['kind' => 'marking']));
        $entry = SalesKnowledge::where('kind', 'marking')->sole();
        $this->assertSame("Drinking water\nfrom 2024", $entry->body);
        $this->actingAs($admin)->get(route('admin.sales-knowledge.index', ['kind' => 'marking']))->assertSee('Water &lt;b&gt;', false)->assertDontSee('Water <b>', false);
        $this->assertStringContainsString("### Water <b>\nDrinking water", SalesAssistantSetting::current()->systemPrompt());

        $this->actingAs($admin)->put(route('admin.sales-knowledge.update', $entry), ['kind' => 'marking', 'title' => 'Water', 'body' => 'x']);
        $this->assertFalse($entry->fresh()->is_active, 'unticked = not used');
        $this->assertStringNotContainsString('### Water', SalesAssistantSetting::current()->systemPrompt());

        $this->actingAs($admin)->post(route('admin.sales-knowledge.store'), ['kind' => 'nope', 'title' => '', 'body' => ''])->assertSessionHasErrors(['kind', 'title', 'body']);
        $this->actingAs($admin)->delete(route('admin.sales-knowledge.destroy', $entry))->assertRedirect();
        $this->assertModelMissing($entry);
    }

    public function test_employees_never_get_the_assistant_and_testers_get_it_in_test_mode(): void
    {
        $s = SalesAssistantSetting::current();
        $s->update(['bot_mode' => 'test', 'business_mode' => 'on', 'employee_ids' => "555\n@boss"]);
        $tester = TelegramUser::create(['telegram_id' => '1', 'is_tester' => true]);
        $customer = TelegramUser::create(['telegram_id' => '2']);
        $employee = TelegramUser::create(['telegram_id' => '3', 'is_employee' => true, 'is_tester' => true]);
        $listed = TelegramUser::create(['telegram_id' => '555']);
        $boss = TelegramUser::create(['telegram_id' => '6', 'username' => 'Boss']);

        $this->assertTrue($s->enabledFor('telegram', $tester));
        $this->assertFalse($s->enabledFor('telegram', $customer));
        $this->assertTrue($s->enabledFor('business', $customer));
        foreach ([$employee, $listed, $boss] as $u) {
            $this->assertFalse($s->enabledFor('business', $u), $u->telegram_id);
        }
        $this->assertFalse($s->enabledFor('business', null, '555'));
        $s->update(['business_mode' => 'off']);
        $this->assertFalse($s->enabledFor('business', $tester));
    }

    public function test_working_hours_are_tashkent_weekdays(): void
    {
        $s = SalesAssistantSetting::current();
        $this->assertTrue($s->isWorkingTime(Carbon::parse('2026-11-23 09:00', 'Asia/Tashkent'))); // Monday
        $this->assertFalse($s->isWorkingTime(Carbon::parse('2026-11-23 18:00', 'Asia/Tashkent')));
        $this->assertFalse($s->isWorkingTime(Carbon::parse('2026-11-22 12:00', 'Asia/Tashkent'))); // Sunday
        $this->assertTrue($s->isWorkingTime(Carbon::parse('2026-11-23 05:30', 'UTC'))); // 10:30 Tashkent
    }

    public function test_user_page_ticks_employee_and_tester(): void
    {
        $admin = $this->admin();
        $user = TelegramUser::create(['telegram_id' => '77']);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $user), ['is_employee' => '1', 'is_tester' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([true, false], [$user->fresh()->is_employee, $user->fresh()->is_tester], 'an employee is never a tester');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => 'employees']))->assertOk()->assertSee('Employees');
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertSee(route('admin.telegram-users.show', $user));
    }
}
