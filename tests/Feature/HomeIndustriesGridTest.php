<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Industry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Home page "Industries & customers" block: industry tabs with a panel per industry showing a
 * customer count (never names), linking to /{locale}/customers#industry-{id} only when it has customers.
 */
class HomeIndustriesGridTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['uz', 'ru', 'en'];

    private function t(string $value): array
    {
        return ['uz' => "{$value} uz", 'ru' => "{$value} ru", 'en' => "{$value} en"];
    }

    private function industry(string $name, ?string $description = null, string $icon = 'shopping-cart'): Industry
    {
        return Industry::create([
            'name' => $this->t($name),
            'description' => $description === null ? null : $this->t($description),
            'icon' => $icon,
            'is_active' => true,
        ]);
    }

    private function customers(Industry $industry, int $count, bool $active = true, string $prefix = 'Client'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Customer::create([
                'name' => "{$prefix} {$industry->id}-{$i}",
                'industry_id' => $industry->id,
                'is_active' => $active,
            ]);
        }
    }

    /** HTML of the #industries section only. */
    private function section(string $html): string
    {
        $start = strpos($html, '<section id="industries"');
        $this->assertNotFalse($start, 'industries section missing');
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end - $start + strlen('</section>'));
    }

    public function test_no_industries_shows_empty_state_in_all_locales(): void
    {
        foreach (self::LOCALES as $locale) {
            $section = $this->section($this->get("/{$locale}")->assertOk()->getContent());
            $this->assertStringContainsString(e(__('site.industries.empty_title', [], $locale)), $section);
            $this->assertStringNotContainsString('<ul', $section);
        }
    }

    public function test_tabs_with_zero_one_and_many_customers(): void
    {
        $none = $this->industry('Logistics', 'Warehouses');
        $one = $this->industry('Retail', 'Shops');
        $many = $this->industry('Pharma', null, 'not-a-real-icon');
        $this->customers($one, 1);
        $this->customers($many, 21);
        $this->customers($none, 3, active: false, prefix: 'Hidden'); // inactive customers are not counted

        foreach (self::LOCALES as $locale) {
            $html = $this->get("/{$locale}")->assertOk()->getContent();
            $section = $this->section($html);

            // No raw translation keys and no customer names.
            $this->assertDoesNotMatchRegularExpression('/site\.[a-z_]+\.[a-z_]+/', $section, "raw key in {$locale}");
            $this->assertStringNotContainsString('Client ', $section);
            $this->assertStringNotContainsString('Hidden ', $section);

            // Three tabs and three panels, one heading each; only the first panel is visible initially.
            $this->assertStringContainsString('role="tablist"', $section);
            $this->assertSame(3, substr_count($section, 'role="tab"'));
            $this->assertSame(3, substr_count($section, 'role="tabpanel"'));
            $this->assertSame(3, substr_count($section, '<h3'));
            $this->assertSame(2, substr_count($section, 'style="display: none;"'));
            foreach ([$none, $one, $many] as $ind) {
                $this->assertStringContainsString("aria-controls=\"ind-panel-{$ind->id}\"", $section);
                $this->assertStringContainsString("aria-labelledby=\"ind-tab-{$ind->id}\"", $section);
            }
            $this->assertSame(1, substr_count($section, 'aria-selected="true"'));
            $this->assertStringContainsString('@keydown.home.prevent', $section);
            $this->assertStringContainsString('@keydown.end.prevent', $section);
            foreach (['Logistics', 'Retail', 'Pharma'] as $name) {
                $this->assertStringContainsString("{$name} {$locale}", $section);
            }

            // The Customers page is gone: no links to customers at all.
            $this->assertStringNotContainsString('/customers', $section);
            $this->assertSame(0, substr_count($section, '<a '));
            $this->assertSame(0, substr_count($section, '</a>'));

            // Counts with the correct plural form.
            $this->assertStringContainsString(e(trans_choice('site.industries.customers_count', 1, [], $locale)), $section);
            $this->assertStringContainsString(e(trans_choice('site.industries.customers_count', 21, [], $locale)), $section);
            $this->assertMatchesRegularExpression('/>\s*21\s*</', $section);
            $this->assertStringContainsString(e(__('site.industries.no_customers', [], $locale)), $section);
            $this->assertSame(0, substr_count($section, e(__('site.industries.see_customers', [], $locale))));

            // Balanced markup.
            $this->assertSame(substr_count($section, '<div'), substr_count($section, '</div>'), "unbalanced divs in {$locale}");
            $this->assertSame(preg_match_all('/<p[\s>]/', $section), substr_count($section, '</p>'));
            $this->assertSame(substr_count($section, '<span'), substr_count($section, '</span>'));

            // No customer logos.
            $this->assertStringNotContainsString('<img', $section);

            // No nested links.
            preg_match_all('#<a\b[^>]*>(.*?)</a>#s', $section, $links);
            foreach ($links[1] as $inner) {
                $this->assertStringNotContainsString('<a ', $inner);
            }
        }

        // Russian plural forms.
        $this->assertSame('клиент в этой отрасли', trans_choice('site.industries.customers_count', 1, [], 'ru'));
        $this->assertSame('клиент в этой отрасли', trans_choice('site.industries.customers_count', 21, [], 'ru'));
        $this->assertSame('клиентов в этой отрасли', trans_choice('site.industries.customers_count', 5, [], 'ru'));
        $this->assertSame('customers in this industry', trans_choice('site.industries.customers_count', 21, [], 'en'));
    }

    public function test_single_industry_with_long_cyrillic_text(): void
    {
        $long = str_repeat('Ўзбекистон маҳсулотларини рақамли маркировкалаш ', 40);
        Industry::create([
            'name' => ['uz' => 'Oʻzbekiston savdosi', 'ru' => 'Торговля', 'en' => 'Retail'],
            'description' => ['uz' => $long, 'ru' => $long, 'en' => $long],
            'icon' => 'shopping-cart',
            'is_active' => true,
        ]);

        foreach (self::LOCALES as $locale) {
            $section = $this->section($this->get("/{$locale}")->assertOk()->getContent());
            $this->assertSame(1, substr_count($section, 'role="tab"'));
            $this->assertStringContainsString('break-words', $section);
            $this->assertStringContainsString(e($long), $section);
            $this->assertStringNotContainsString('<a ', $section);
            $this->assertStringContainsString(e(__('site.industries.no_customers', [], $locale)), $section);
        }
    }

    public function test_count_includes_unlisted_customers(): void
    {
        $industry = $this->industry('Retail');
        $industry->update(['extra_customers' => 4]);
        $this->customers($industry, 1);

        foreach (self::LOCALES as $locale) {
            $section = $this->section($this->get("/{$locale}")->assertOk()->getContent());
            $this->assertMatchesRegularExpression('/>\s*5\s*</', $section);
            $this->assertStringContainsString(e(trans_choice('site.industries.customers_count', 5, [], $locale)), $section);
            $this->assertStringNotContainsString('Client ', $section);
        }
    }

    public function test_customers_page_is_gone_and_names_stay_private(): void
    {
        $a = $this->industry('Retail');
        $this->customers($a, 2);

        foreach (self::LOCALES as $locale) {
            $this->get("/{$locale}/customers")->assertStatus(301)->assertRedirect(route('site.industries', $locale));
            $html = $this->get(route('site.industries', $locale))->assertOk()->getContent();
            $this->assertStringNotContainsString('Client ', $html);
            $this->assertStringContainsString(e(trans_choice('site.industries.customers_count', 2, [], $locale)), $html);
            $this->assertStringNotContainsString(route('site.customers', $locale), $this->get("/{$locale}")->getContent()); // not in the menu
        }
    }
}
