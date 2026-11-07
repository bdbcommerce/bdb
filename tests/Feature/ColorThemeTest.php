<?php

namespace Tests\Feature;

use App\Models\ColorTheme;
use App\Models\User;
use Database\Seeders\ColorThemeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ColorThemeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_seeder_installs_locked_default_as_active_plus_presets_idempotently(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $this->seed(ColorThemeSeeder::class);

        $this->assertSame(1 + count(ColorTheme::PRESETS), ColorTheme::count());
        $default = ColorTheme::where('is_locked', true)->sole();
        $this->assertTrue($default->is_active);
        $this->assertSame(ColorTheme::DEFAULT['accent'], $default->accent);
        $this->assertSame('', $default->css(), 'default keeps the compiled look');
    }

    public function test_every_preset_keeps_button_text_readable(): void
    {
        foreach ([ColorTheme::DEFAULT, ...ColorTheme::PRESETS] as $preset) {
            $this->assertGreaterThanOrEqual(ColorTheme::MIN_CONTRAST, ColorTheme::contrastWithWhite($preset['accent']), $preset['name']);
        }
    }

    public function test_site_has_no_theme_css_with_default_and_gets_it_after_switching(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $this->get('/en')->assertOk()->assertDontSee('id="site-theme"', false);

        ColorTheme::where('name', 'Ocean Blue')->sole()->activate();

        $html = $this->get('/en')->assertOk()->getContent();
        $this->assertStringContainsString('<style id="site-theme">:root{--color-brand-50:', $html);
        $this->assertStringContainsString('--color-brand-600:#1d4ed8', $html);
        $this->assertStringContainsString('--color-gray-900:oklch(20.8% 0.042 265.755)', $html); // slate
    }

    public function test_only_one_theme_is_active(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $this->actingAs($this->admin())
            ->post(route('admin.color-themes.activate', ColorTheme::where('name', 'Emerald')->sole()))
            ->assertRedirect(route('admin.color-themes.index'));

        $this->assertSame(['Emerald'], ColorTheme::where('is_active', true)->pluck('name')->all());
        $this->assertSame('Emerald', ColorTheme::active()->name);
    }

    public function test_admin_can_create_custom_theme_and_use_it(): void
    {
        $this->actingAs($this->admin())->get(route('admin.color-themes.index'))->assertOk()->assertSee('BDB Red (default)');

        $this->post(route('admin.color-themes.store'), [
            'name' => 'Campaign', 'accent' => ' #7C3AED ', 'neutral' => 'zinc', 'activate' => '1',
        ])->assertRedirect(route('admin.color-themes.index'));

        $theme = ColorTheme::where('name', 'Campaign')->sole();
        $this->assertSame('#7c3aed', $theme->accent);
        $this->assertTrue($theme->is_active);
        $this->get('/ru')->assertSee('--color-brand-600:#7c3aed', false);
    }

    public function test_too_light_or_invalid_accent_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('admin.color-themes.store'), ['name' => 'Light', 'accent' => '#fde047', 'neutral' => 'gray'])
            ->assertSessionHasErrors('accent');
        $this->post(route('admin.color-themes.store'), ['name' => 'Bad', 'accent' => 'red;}body{', 'neutral' => 'gray'])
            ->assertSessionHasErrors('accent');
        $this->post(route('admin.color-themes.store'), ['name' => 'Bad', 'accent' => '#1d4ed8', 'neutral' => 'pink'])
            ->assertSessionHasErrors('neutral');
        $this->assertSame(0, ColorTheme::whereIn('name', ['Light', 'Bad'])->count());
    }

    public function test_default_cannot_be_edited_or_deleted_and_live_theme_cannot_be_deleted(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $this->actingAs($this->admin());
        $default = ColorTheme::where('is_locked', true)->sole();

        $this->get(route('admin.color-themes.edit', $default))->assertRedirect(route('admin.color-themes.create', ['from' => $default->id]));
        $this->put(route('admin.color-themes.update', $default), ['name' => 'X', 'accent' => '#1d4ed8', 'neutral' => 'gray'])->assertForbidden();
        $this->delete(route('admin.color-themes.destroy', $default))->assertSessionHasErrors('theme');

        $blue = ColorTheme::where('name', 'Ocean Blue')->sole();
        $blue->activate();
        $this->delete(route('admin.color-themes.destroy', $blue))->assertSessionHasErrors('theme');

        $default->activate();
        $this->delete(route('admin.color-themes.destroy', $blue))->assertRedirect(route('admin.color-themes.index'));
        $this->assertModelMissing($blue);
        $this->assertSame(ColorTheme::DEFAULT['accent'], $default->fresh()->accent);
    }

    public function test_edit_and_duplicate_forms_render(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $this->actingAs($this->admin());
        $copper = ColorTheme::where('name', 'Copper')->sole();

        $this->get(route('admin.color-themes.edit', $copper))->assertOk()->assertSee('#b45309');
        $this->get(route('admin.color-themes.create', ['from' => $copper->id]))->assertOk()->assertSee('Copper copy');
    }

    public function test_preview_parameter_only_works_for_admins(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $teal = ColorTheme::where('name', 'Deep Teal')->sole();

        $this->get("/en?theme_preview={$teal->id}")->assertDontSee('#0f766e', false);
        $this->actingAs($this->admin())->get("/en?theme_preview={$teal->id}")
            ->assertSee('--color-brand-600:#0f766e', false)->assertSee('Exit preview');

        // the preview follows the admin to other pages until it is switched off
        $this->get('/en/brands')->assertSee('--color-brand-600:#0f766e', false);
        $this->get('/en/about?theme_preview=off')->assertDontSee('#0f766e', false)->assertDontSee('Exit preview');
        $this->get('/en')->assertDontSee('#0f766e', false);
    }

    public function test_deleted_presets_stay_deleted_but_the_default_is_always_restored(): void
    {
        $this->seed(ColorThemeSeeder::class);
        $this->actingAs($this->admin());

        $this->delete(route('admin.color-themes.destroy', ColorTheme::where('name', 'Ocean Blue')->sole()));
        $this->get(route('admin.color-themes.index'))->assertOk()->assertDontSee('Ocean Blue');
        $this->seed(ColorThemeSeeder::class);
        $this->assertFalse(ColorTheme::where('name', 'Ocean Blue')->exists());

        ColorTheme::where('is_locked', true)->delete(); // e.g. removed by hand in the database
        $this->get(route('admin.color-themes.index'))->assertOk()->assertSee('BDB Red (default)');
    }

    public function test_component_colors_are_saved_and_applied_to_the_site(): void
    {
        $this->actingAs($this->admin())->post(route('admin.color-themes.store'), [
            'name' => 'Dark footer', 'accent' => '#1d4ed8', 'neutral' => 'slate', 'activate' => '1',
            'components' => [
                'footer-bg' => '#0B1F3A', 'footer-text' => '#cbd5e1', 'header-bg' => '#ffffff',
                'nav-active' => '', 'unknown-part' => '#000000',
            ],
        ])->assertSessionHasNoErrors();

        $theme = ColorTheme::where('name', 'Dark footer')->sole();
        $this->assertSame(['footer-bg' => '#0b1f3a', 'footer-text' => '#cbd5e1', 'header-bg' => '#ffffff'], $theme->components);

        $html = $this->get('/en')->assertOk()->getContent();
        $this->assertStringContainsString('--site-footer-bg:#0b1f3a', $html);
        $this->assertStringContainsString('--site-footer-text:#cbd5e1', $html);
        $this->assertStringNotContainsString('--site-nav-active:', $html, 'Auto parts follow the accent');
        $this->assertStringNotContainsString('unknown-part', $html);
        $this->assertStringContainsString('bg-(color:--site-footer-bg)', $html);
    }

    public function test_unreadable_custom_component_pairs_and_bad_codes_are_rejected(): void
    {
        $this->actingAs($this->admin());
        $base = ['name' => 'Bad parts', 'accent' => '#1d4ed8', 'neutral' => 'gray'];

        // light grey text on a white header
        $this->post(route('admin.color-themes.store'), $base + ['components' => ['nav-text' => '#d1d5db']])
            ->assertSessionHasErrors('components');
        // yellow button with white (automatic) text
        $this->post(route('admin.color-themes.store'), $base + ['components' => ['btn-bg' => '#facc15']])
            ->assertSessionHasErrors('components');
        // not a colour code / CSS injection attempt
        $this->post(route('admin.color-themes.store'), $base + ['components' => ['footer-bg' => 'red;}body{']])
            ->assertSessionHasErrors('components.footer-bg');
        $this->assertFalse(ColorTheme::where('name', 'Bad parts')->exists());

        // a dark text colour on the same yellow button is fine
        $this->post(route('admin.color-themes.store'), $base + ['components' => ['btn-bg' => '#facc15', 'btn-text' => '#111827', 'btn-hover' => '#eab308']])
            ->assertSessionHasNoErrors();
    }

    public function test_editor_shows_all_site_parts_and_duplicate_keeps_component_colors(): void
    {
        $this->actingAs($this->admin());
        $theme = ColorTheme::create(['name' => str_repeat('N', 60), 'accent' => '#1d4ed8', 'neutral' => 'gray',
            'components' => ['footer-bg' => '#0b1f3a']]);

        $page = $this->get(route('admin.color-themes.create', ['from' => $theme->id]))->assertOk();
        foreach (ColorTheme::COMPONENTS as $group) {
            $page->assertSee($group['label']);
        }
        $page->assertSee('#0b1f3a')->assertSee(str_repeat('N', 55).' copy');
        $this->assertSame(60, mb_strlen(str_repeat('N', 55).' copy'));
    }

    public function test_get_a_quote_is_gone_and_header_leads_to_the_shop(): void
    {
        foreach (['uz', 'ru', 'en'] as $lc) {
            $html = $this->get("/{$lc}")->assertOk()->getContent();
            foreach (['Get a quote', 'Получить расчёт', 'Narx hisobini olish'] as $old) {
                $this->assertStringNotContainsString($old, $html);
            }
            $this->assertStringContainsString(e(__('site.cta.contact', [], $lc)), $html);
            $this->assertStringContainsString('utm_campaign=header', $html);
        }
    }

    public function test_array_component_value_is_a_validation_error_and_hover_colors_are_checked(): void
    {
        $this->actingAs($this->admin());
        $base = ['name' => 'Edge', 'accent' => '#1d4ed8', 'neutral' => 'gray'];

        $this->post(route('admin.color-themes.store'), $base + ['components' => ['header-bg' => ['#ffffff']]])
            ->assertSessionHasErrors('components.header-bg');
        $this->post(route('admin.color-themes.store'), $base + ['components' => ['footer-link-hover' => '#111827']])
            ->assertSessionHasErrors('components');
        $this->post(route('admin.color-themes.store'), $base + ['components' => ['link-hover' => '#ffffff']])
            ->assertSessionHasErrors('components');
        $this->assertFalse(ColorTheme::where('name', 'Edge')->exists());
    }

    public function test_missing_default_is_restored_even_if_its_name_was_taken(): void
    {
        ColorTheme::create(['name' => ColorTheme::DEFAULT['name'], 'accent' => '#1d4ed8', 'neutral' => 'slate']);

        $this->actingAs($this->admin())->get(route('admin.color-themes.index'))->assertOk();
        $default = ColorTheme::where('is_locked', true)->sole();
        $this->assertSame(ColorTheme::DEFAULT['accent'], $default->accent);
    }

    public function test_site_still_renders_without_any_theme_rows(): void
    {
        $this->get('/uz')->assertOk()->assertDontSee('id="site-theme"', false);
    }

    public function test_guests_cannot_manage_themes(): void
    {
        $this->get(route('admin.color-themes.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.color-themes.store'), ['name' => 'X', 'accent' => '#1d4ed8', 'neutral' => 'gray'])
            ->assertRedirect(route('admin.login'));
    }
}
