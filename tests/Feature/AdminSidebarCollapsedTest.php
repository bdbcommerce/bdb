<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sidebar menu groups start collapsed; only the current page's group is open. */
class AdminSidebarCollapsedTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_are_collapsed_except_the_current_one(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/aria-expanded="true"\s+aria-controls="nav-group-/', $html);
        $this->assertSame(5, preg_match_all('/aria-expanded="false"\s+aria-controls="nav-group-/', $html));
        foreach (['company', 'catalog', 'content', 'assistant', 'telegram'] as $key) {
            $this->assertMatchesRegularExpression('/<ul id="nav-group-'.$key.'"[^>]*x-cloak/', $html, $key);
        }

        $html = $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<ul id="nav-group-telegram"[^>]*x-cloak/', $html);
        $this->assertMatchesRegularExpression('/<ul id="nav-group-assistant"[^>]*x-cloak/', $html);
        $this->assertStringContainsString('bdb.admin.nav.v2.telegram', $html);
    }
}
