<?php

namespace Tests\Feature;

use App\Models\InvoiceParserSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** After saving with "on" ticked but something missing, the page explains it and the switch shows the saved (off) state. */
class InvoiceParserIncompleteSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_shows_saved_and_missing_items_with_switch_unchecked(): void
    {
        config(['services.anthropic.key' => 'sk-ant-env-secret']);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->from(route('admin.invoice-parser.edit'))
            ->put(route('admin.invoice-parser.update'), ['enabled' => '1', 'model' => array_key_first(InvoiceParserSetting::MODELS), 'target_chat_id' => '-1002'])
            ->assertRedirect(route('admin.invoice-parser.edit'));

        $html = $this->actingAs($admin)->get(route('admin.invoice-parser.edit'))->assertOk()
            ->assertSee('Invoice parser settings saved.')
            ->assertSee('missing: the source chat ID.', false)
            ->assertDontSee('ANTHROPIC_API_KEY in .env,', false)
            ->assertDontSee('sk-ant-env-secret')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="enabled" value="1" checked/', $html);
        $this->assertSame('-1002', InvoiceParserSetting::current()->target_chat_id);
        $this->assertFalse(InvoiceParserSetting::current()->enabled);
    }

    public function test_running_parser_is_switched_off_when_a_required_chat_is_cleared(): void
    {
        config(['services.anthropic.key' => 'sk-ant-env']);
        $admin = User::factory()->create(['is_admin' => true]);
        $s = InvoiceParserSetting::current();
        $s->forceFill(['enabled' => true, 'source_chat_id' => '-1001', 'target_chat_id' => '-1002'])->save();

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['enabled' => '1', 'model' => array_key_first(InvoiceParserSetting::MODELS), 'source_chat_id' => '-1001'])
            ->assertSessionHasErrors('enabled');

        $s->refresh();
        $this->assertFalse($s->enabled);
        $this->assertNull($s->target_chat_id);
    }

    public function test_unticked_switch_with_nothing_set_saves_without_error(): void
    {
        config(['services.anthropic.key' => null]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['model' => array_key_first(InvoiceParserSetting::MODELS)])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
    }
}
