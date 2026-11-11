<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Careers is in the menu list once: desktop nav, mobile menu and footer (one link each). */
class CareersNavLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_careers_link_appears_once_per_menu(): void
    {
        foreach (['uz', 'ru', 'en'] as $locale) {
            $html = $this->get("/{$locale}")->assertOk()->getContent();
            $href = 'href="'.route('site.careers', $locale).'"';
            $this->assertSame(3, substr_count($html, $href), "{$locale}: desktop nav, mobile menu, footer");
            $current = '#<a href="'.preg_quote(route('site.careers', $locale), '#').'"[^>]*aria-current="page"#';
            $this->assertSame(0, preg_match_all($current, $html));

            // On the Careers page it is marked current in the desktop nav and the mobile menu.
            $careers = $this->get("/{$locale}/careers")->assertOk()->getContent();
            $this->assertSame(2, preg_match_all($current, $careers));
        }
    }
}
