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
        $this->assertSame(9, preg_match_all('/aria-expanded="false"\s+aria-controls="nav-group-/', $html));
        foreach (['inbox', 'conversations', 'ai-team', 'reports', 'company', 'catalog', 'content', 'careers', 'settings'] as $key) {
            $this->assertMatchesRegularExpression('/<ul id="nav-group-'.$key.'"[^>]*x-cloak/', $html, $key);
        }

        $html = $this->actingAs($admin)->get(route('admin.telegram-users.index'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<ul id="nav-group-conversations"[^>]*x-cloak/', $html);
        $this->assertMatchesRegularExpression('/<ul id="nav-group-inbox"[^>]*x-cloak/', $html);
        $this->assertStringContainsString("openGroup: 'conversations'", $html); // accordion: only this group open
        $this->assertStringNotContainsString('bdb.admin.nav.', $html); // no remembered open groups
    }
}
