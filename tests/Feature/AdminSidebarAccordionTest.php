<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Sidebar accordion: one shared openGroup on <nav>, at most one group rendered open, matching openGroup. */
class AdminSidebarAccordionTest extends TestCase
{
    use RefreshDatabase;

    private function state(string $html): array
    {
        preg_match('/x-data="\{ openGroup: ([^}]*) \}"/', $html, $og);
        preg_match_all('/<ul id="nav-group-([a-z-]+)"([^>]*)>/', $html, $m, PREG_SET_ORDER);
        $open = collect($m)->reject(fn ($x) => str_contains($x[2], 'x-cloak'))->map(fn ($x) => $x[1])->values()->all();

        return [$og[1] ?? null, $open, count($m)];
    }

    public function test_every_menu_page_opens_only_its_own_group(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        preg_match('/<nav class="flex-1[^>]*aria-label="Main".*?<\/nav>/s', $html, $nav);
        preg_match_all('/<a href="([^"]+)"/', $nav[0], $links);
        $urls = array_unique($links[1]);
        $this->assertGreaterThan(20, count($urls));

        foreach ($urls as $url) {
            $page = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            [$og, $open, $groups] = $this->state($page);
            $this->assertSame(9, $groups, $url);
            $this->assertLessThanOrEqual(1, count($open), $url.' opens '.implode(',', $open));
            if ($open) {
                $this->assertSame("'".$open[0]."'", $og, $url);
                $this->assertMatchesRegularExpression('/<a[^>]+aria-current="page"/', $page, $url);
            } else {
                $this->assertContains($og, ["'main'", 'null'], $url);
            }
            // Each group's getter/setter binds to its own key.
            preg_match_all("/get open\(\) \{ return this.openGroup === '([a-z-]+)' \}, set open\(v\) \{ this.openGroup = v \? '([a-z-]+)' : null \}/", $page, $g, PREG_SET_ORDER);
            $this->assertCount(9, $g, $url);
            foreach ($g as [, $get, $set]) {
                $this->assertSame($get, $set);
            }
        }
    }

    public function test_all_admin_pages_keep_the_invariant(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.') || $name === 'admin.login'
                || ! in_array('GET', $route->methods()) || str_contains($route->uri(), '{')) {
                continue;
            }
            $res = $this->actingAs($admin)->get(route($name));
            if ($res->status() !== 200 || ! str_contains($res->getContent(), 'aria-label="Main"')) {
                continue;
            }
            [$og, $open] = $this->state($res->getContent());
            $this->assertLessThanOrEqual(1, count($open), $name);
            $this->assertSame($open ? "'".$open[0]."'" : $og, $og, $name);
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
    }
}
