<?php

namespace Tests\Feature;

use App\Models\InvoiceParserSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ticking "on" without everything it needs still saves the rest of the form; only the switch stays off. */
class InvoiceParserSaveWhenIncompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_saved_without_a_key_and_the_parser_stays_off(): void
    {
        config(['services.anthropic.key' => null]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), [
            'enabled' => '1', 'model' => 'claude-sonnet-5-5', 'source_chat_id' => '-1001', 'source_from_ids' => '5, 6',
            'target_chat_id' => '-1002', 'json_chat_id' => '-1003', 'daily_limit' => '7', 'prompt' => 'Read it.',
        ])->assertRedirect()->assertSessionHasErrors('enabled')->assertSessionHas('success');

        $s = InvoiceParserSetting::current();
        $this->assertFalse($s->enabled);
        $this->assertSame(['claude-sonnet-5-5', '-1001', '5, 6', '-1002', '-1003', 7, 'Read it.'],
            [$s->model, $s->source_chat_id, $s->source_from_ids, $s->target_chat_id, $s->json_chat_id, $s->dailyLimit(), $s->prompt]);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', session('errors')->first('enabled'));

        config(['services.anthropic.key' => 'sk-ant-env']);
        $this->actingAs($admin)->put(route('admin.invoice-parser.update'), ['enabled' => '1', 'model' => 'claude-sonnet-5-5', 'source_chat_id' => '-1001', 'target_chat_id' => '-1002'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(InvoiceParserSetting::current()->enabled);
    }
}
