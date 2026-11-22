<?php

namespace Tests\Feature;

use App\Models\SalesAssistantSetting;
use App\Models\SalesKnowledge;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** QA coverage for Admin → Sales assistant: access, validation edge cases, escaping, sidebar, migration. */
class SalesAssistantAdminQaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function valid(array $override = []): array
    {
        return $override + ['bot_mode' => 'off', 'business_mode' => 'off', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 2, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => 'claude-haiku-5-5', 'employee_ids' => ''];
    }

    public function test_guests_are_sent_to_login_and_non_admins_cannot_change_anything(): void
    {
        $entry = SalesKnowledge::first();
        $gets = [route('admin.sales-assistant.edit'), route('admin.sales-assistant.prompt'), route('admin.sales-knowledge.index'),
            route('admin.sales-knowledge.create'), route('admin.sales-knowledge.edit', $entry)];
        foreach ($gets as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $this->put(route('admin.sales-assistant.prompt.update'), ['prompt' => str_repeat('x', 300)])->assertRedirect(route('admin.login'));
        $this->delete(route('admin.sales-knowledge.destroy', $entry))->assertRedirect(route('admin.login'));

        $user = User::factory()->create(['is_admin' => false]);
        foreach ($gets as $url) {
            $this->actingAs($user)->get($url)->assertRedirect();
        }
        $this->actingAs($user)->put(route('admin.sales-assistant.prompt.update'), ['prompt' => str_repeat('x', 300)])->assertRedirect();
        $this->actingAs($user)->post(route('admin.sales-knowledge.store'), ['kind' => 'product', 'title' => 'Hack', 'body' => 'x'])->assertRedirect();
        $this->actingAs($user)->delete(route('admin.sales-knowledge.destroy', $entry))->assertRedirect();
        $this->assertNull(SalesAssistantSetting::current()->prompt);
        $this->assertModelExists($entry);
        $this->assertSame(0, SalesKnowledge::where('title', 'Hack')->count());
    }

    public function test_settings_edge_cases_are_validation_errors_not_crashes(): void
    {
        $admin = $this->admin();
        $cases = [
            'bot_mode' => [['on'], ''],
            'work_days' => [[8], [0], ['abc'], [[1]], 'mon'],
            'work_start' => ['9:00', '25:00', '09:00:00', '', ['09:00']],
            'work_end' => ['09:00', '24:00'], // equal to start / not a valid H:i
            'wait_minutes' => [-1, 121, '1e20', 'abc', [5]],
            'remind_after_hours' => [0, 49],
            'close_after_hours' => [2, 169], // not greater than the reminder (2) / above max
            'follow_up_days' => [0, 91],
            'model' => ['gpt-4', ['claude-haiku-5-5']],
            'employee_ids' => [str_repeat('1', 3001), ['123']],
        ];
        foreach ($cases as $field => $values) {
            foreach ($values as $value) {
                $this->actingAs($admin)->from(route('admin.sales-assistant.edit'))
                    ->put(route('admin.sales-assistant.update'), $this->valid([$field => $value]))
                    ->assertRedirect(route('admin.sales-assistant.edit'))
                    ->assertSessionHas('errors', fn ($errors) => collect($errors->keys())->contains(fn ($k) => $k === $field || str_starts_with($k, $field.'.')));
            }
        }
        $this->assertSame('off', SalesAssistantSetting::current()->bot_mode);

        // Duplicate / string day numbers are normalised.
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $this->valid(['work_days' => ['6', '1', '1'], 'work_start' => '00:00', 'work_end' => '23:59', 'wait_minutes' => 0]))
            ->assertSessionHasNoErrors();
        $s = SalesAssistantSetting::current();
        $this->assertSame([1, 6], $s->work_days);
        $this->assertSame(0, $s->wait_minutes);
        $this->assertStringContainsString('Mon, Sat 00:00–23:59', $s->systemPrompt());
    }

    public function test_old_input_is_kept_after_a_validation_error(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->from(route('admin.sales-assistant.edit'))
            ->put(route('admin.sales-assistant.update'), $this->valid(['work_start' => '10:15', 'work_end' => '08:00', 'employee_ids' => '@kept_value']));
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))
            ->assertOk()->assertSee('value="10:15"', false)->assertSee('@kept_value')->assertSee('The end of the working day must be after its start.');

        $this->actingAs($admin)->from(route('admin.sales-knowledge.create', ['kind' => 'device']))
            ->post(route('admin.sales-knowledge.store'), ['kind' => 'device', 'title' => 'Zebra ZT411', 'body' => '']);
        $this->actingAs($admin)->get(route('admin.sales-knowledge.create', ['kind' => 'device']))->assertOk()->assertSee('value="Zebra ZT411"', false);
    }

    public function test_knowledge_and_prompt_edge_cases(): void
    {
        $admin = $this->admin();
        foreach ([
            ['title' => str_repeat('a', 151)], ['body' => str_repeat('a', 5001)], ['sort' => 100001], ['sort' => -1],
            ['title' => ['x']], ['body' => ['x']], ['kind' => ['product']], ['sort' => ['1']],
        ] as $bad) {
            $this->actingAs($admin)->post(route('admin.sales-knowledge.store'), $bad + ['kind' => 'device', 'title' => 'T', 'body' => 'B'])
                ->assertSessionHasErrors(array_keys($bad));
        }
        $this->assertSame(0, SalesKnowledge::where('kind', 'device')->count());

        foreach ([str_repeat('я', 30001), ['x'], ''] as $bad) {
            $this->actingAs($admin)->put(route('admin.sales-assistant.prompt.update'), ['prompt' => $bad])->assertSessionHasErrors('prompt');
        }

        // Cyrillic / Uzbek Latin at the limits are kept as typed.
        $title = str_repeat('Ж', 150);
        $body = "Oʻzbekiston: gʻalla\n".str_repeat('ш', 4900);
        $this->actingAs($admin)->post(route('admin.sales-knowledge.store'), ['kind' => 'device', 'title' => $title, 'body' => $body, 'is_active' => '1'])->assertSessionHasNoErrors();
        $e = SalesKnowledge::where('kind', 'device')->sole();
        $this->assertSame([$title, $body, 10], [$e->title, $e->body, $e->sort]);

        $this->actingAs($admin)->get(route('admin.sales-knowledge.edit', 999999))->assertNotFound();
    }

    public function test_html_in_knowledge_and_prompt_is_escaped_everywhere(): void
    {
        $admin = $this->admin();
        $xss = '<script>alert(1)</script>';
        $this->actingAs($admin)->post(route('admin.sales-knowledge.store'), ['kind' => 'software', 'title' => "T $xss", 'body' => "B $xss", 'is_active' => '1']);
        $entry = SalesKnowledge::where('kind', 'software')->sole();
        $prompt = SalesAssistantSetting::defaultPrompt()."\n</textarea></pre>\" ' {$xss} `\${x}`";
        $this->actingAs($admin)->put(route('admin.sales-assistant.prompt.update'), ['prompt' => $prompt])->assertSessionHasNoErrors();

        foreach ([route('admin.sales-knowledge.index', ['kind' => 'software']), route('admin.sales-knowledge.edit', $entry), route('admin.sales-assistant.prompt')] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($xss, $html, $url);
            $this->assertStringNotContainsString('</textarea></pre>', $html, $url);
        }
        // The x-data attribute stays one attribute (no raw double quote from the prompt).
        $html = $this->actingAs($admin)->get(route('admin.sales-assistant.prompt'))->getContent();
        $this->assertMatchesRegularExpression('/x-data="\{ prompt: [^"]*, original: [^"]*\}"/', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'preview shows the text escaped');
    }

    public function test_sidebar_group_opens_on_its_pages(): void
    {
        $admin = $this->admin();
        $entry = SalesKnowledge::first();
        foreach ([route('admin.sales-assistant.edit') => 'Settings', route('admin.sales-assistant.prompt') => 'Prompt',
            route('admin.sales-knowledge.index') => 'Knowledge', route('admin.sales-knowledge.create') => 'Knowledge', route('admin.sales-knowledge.edit', $entry) => 'Knowledge'] as $url => $label) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/<ul id="nav-group-sales-assistant"[^>]*x-cloak/', $html, $url);
            $this->assertMatchesRegularExpression('/<ul id="nav-group-telegram"[^>]*x-cloak/', $html, $url);
            $this->assertMatchesRegularExpression('/Sales assistant\s*<span[^>]*>\/<\/span>\s*'.$label.'/', $html, $url);
        }
    }

    public function test_telegram_user_flags_and_filters(): void
    {
        $admin = $this->admin();
        $u = TelegramUser::create(['telegram_id' => '901', 'name' => 'Staff One']);
        TelegramUser::create(['telegram_id' => '902', 'name' => 'Plain Customer']);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $u), ['is_employee' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($u->fresh()->is_employee);
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => 'employees']))->assertOk()->assertSee('Staff One')->assertDontSee('Plain Customer');
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $u), ['is_employee' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($u->fresh()->is_employee);
        $this->actingAs($admin)->put(route('admin.telegram-users.update', $u), ['is_employee' => ['1']])->assertSessionHasErrors('is_employee');
        $this->actingAs($admin)->get(route('admin.telegram-users.index', ['filter' => ['employees']]))->assertOk();
    }

    public function test_migration_rolls_back_and_up_again_on_sqlite(): void
    {
        $migration = require database_path('migrations/2026_11_22_120000_create_sales_assistant_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('sales_knowledge'));
        $this->assertFalse(Schema::hasTable('sales_assistant_settings'));
        $this->assertFalse(Schema::hasColumn('telegram_users', 'is_employee'));
        $this->assertTrue(Schema::hasTable('telegram_users'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('telegram_users', 'is_employee'));
        $this->assertSame(array_sum(array_map('count', SalesKnowledge::DEFAULTS)), SalesKnowledge::count());
    }
}
