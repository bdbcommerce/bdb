<?php

namespace Tests\Feature;

use App\Models\SalesAssistantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Large order: from the set total (default 10 000 000 sum) the AI confirms and hands the request to a manager. */
class LargeOrderHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rule_is_in_the_prompt_with_the_default_and_can_be_switched_off(): void
    {
        $s = SalesAssistantSetting::current();
        $this->assertSame(10_000_000, $s->largeOrderSum());
        $this->assertStringContainsString("# LARGE ORDER\nWhen the products the customer wants, priced from PRODUCT CATALOG (price × quantity), add up to 10 000 000 sum or more", $s->systemPrompt());

        $s->update(['large_order_sum' => 25_000_000]);
        $this->assertStringContainsString('add up to 25 000 000 sum', $s->refresh()->systemPrompt());

        $s->update(['large_order_sum' => 0]);
        $this->assertStringNotContainsString('# LARGE ORDER', $s->refresh()->systemPrompt());
    }

    public function test_settings_page_saves_the_total(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.sales-assistant.edit'))->assertOk()->assertSee('Large order from (sum)')->assertSee('value="10000000"', false);
        $s = SalesAssistantSetting::current();
        $form = ['bot_mode' => 'on', 'business_mode' => 'on', 'wait_minutes' => 5, 'work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '18:00',
            'remind_after_hours' => 2, 'close_after_hours' => 24, 'follow_up_days' => 14, 'model' => $s->model];
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form + ['large_order_sum' => 15000000])->assertSessionHasNoErrors();
        $this->assertSame(15_000_000, $s->refresh()->largeOrderSum());
        $this->actingAs($admin)->put(route('admin.sales-assistant.update'), $form + ['large_order_sum' => -1])->assertSessionHasErrors('large_order_sum');
    }
}
