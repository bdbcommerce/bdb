<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanySocialLinksTest extends TestCase
{
    use RefreshDatabase;

    public static function validLinks(): array
    {
        return [
            'telegram handle' => ['telegram', '@bdb_uz', 'https://t.me/bdb_uz'],
            'telegram bare handle' => ['telegram', 'bdb_uz', 'https://t.me/bdb_uz'],
            'telegram without scheme' => ['telegram', 't.me/bdb_uz', 'https://t.me/bdb_uz'],
            'telegram full' => ['telegram', 'https://t.me/bdb_uz', 'https://t.me/bdb_uz'],
            'telegram http upgraded' => ['telegram', 'http://telegram.me/bdb_uz', 'https://telegram.me/bdb_uz'],
            'instagram handle with dot' => ['instagram', '@bdb.uz', 'https://www.instagram.com/bdb.uz'],
            'instagram without scheme' => ['instagram', 'instagram.com/bdb.uz/', 'https://instagram.com/bdb.uz/'],
            'instagram with query' => ['instagram', 'https://www.instagram.com/bdb.uz?igsh=abc', 'https://www.instagram.com/bdb.uz?igsh=abc'],
            'facebook mobile' => ['facebook', 'm.facebook.com/bdb.uz', 'https://m.facebook.com/bdb.uz'],
            'linkedin handle' => ['linkedin', 'bdb-uz', 'https://www.linkedin.com/company/bdb-uz'],
            'youtube handle' => ['youtube', '@bdbuz', 'https://www.youtube.com/@bdbuz'],
            'youtube full' => ['youtube', 'youtube.com/@bdbuz', 'https://youtube.com/@bdbuz'],
            'spaces trimmed' => ['telegram', '  https://t.me/bdb_uz  ', 'https://t.me/bdb_uz'],
        ];
    }

    #[DataProvider('validLinks')]
    public function test_links_and_handles_become_https_urls(string $network, string $input, string $expected): void
    {
        $this->assertSame($expected, CompanyInfo::socialUrl($network, $input));
    }

    public static function invalidLinks(): array
    {
        return [
            'other network' => ['telegram', 'https://instagram.com/bdb'],
            'look-alike host' => ['telegram', 'https://t.me.evil.com/bdb'],
            'javascript' => ['telegram', 'javascript:alert(1)'],
            'data url' => ['instagram', 'data:text/html,<b>x</b>'],
            'ftp' => ['facebook', 'ftp://facebook.com/bdb'],
            'credentials' => ['facebook', 'https://user:pass@facebook.com/bdb'],
            'spaces inside' => ['youtube', 'youtube.com/@bdb uz'],
            'bad handle' => ['telegram', '@bdb<script>'],
            'random domain' => ['linkedin', 'bdb.uz'],
        ];
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_values_are_rejected(string $network, string $input): void
    {
        $this->assertNull(CompanyInfo::socialUrl($network, $input));
    }

    public static function stricterRules(): array
    {
        return [
            'real facebook subdomain' => ['facebook', 'web.facebook.com/bdb.uz', 'https://web.facebook.com/bdb.uz'],
            'linkedin language subdomain' => ['linkedin', 'uz.linkedin.com/company/bdb', 'https://uz.linkedin.com/company/bdb'],
            'youtube music' => ['youtube', 'music.youtube.com/channel/UC123', 'https://music.youtube.com/channel/UC123'],
            'instagram short domain' => ['instagram', 'instagr.am/bdb.uz', 'https://instagr.am/bdb.uz'],
            'linkedin short link' => ['linkedin', 'lnkd.in/abc', 'https://lnkd.in/abc'],
            'bare network home page' => ['telegram', 't.me', null],
            'home page with slash' => ['instagram', 'https://instagram.com/', null],
            'made-up telegram subdomain' => ['telegram', 'm.t.me/bdb_uz', null],
            'telegram handle too short' => ['telegram', '@bdb', null],
            'telegram handle with dot' => ['telegram', '@bdb.uz', null],
            'nul byte' => ['telegram', "t.me/bdb\0x", null],
            'single quote' => ['telegram', "t.me/a'b", null],
            'query without a path' => ['telegram', 't.me/?x=1', null],
            'tab' => ['telegram', "t.me/bdb\tx", null],
            'too long after https:// is added' => ['telegram', 't.me/'.str_repeat('a', 250), null],
        ];
    }

    #[DataProvider('stricterRules')]
    public function test_network_specific_hosts_handles_and_limits(string $network, string $input, ?string $expected): void
    {
        $this->assertSame($expected, CompanyInfo::socialUrl($network, $input));
    }

    public function test_array_input_is_a_validation_error_not_a_crash(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->from(route('admin.company-info.edit'))
            ->put(route('admin.company-info.update'), ['telegram' => ['x']])
            ->assertRedirect(route('admin.company-info.edit'))
            ->assertSessionHasErrors('telegram');
    }

    public function test_admin_form_saves_normalized_links_and_rejects_bad_ones(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.company-info.update'), [
            'telegram' => '@bdb_uz', 'instagram' => 'instagram.com/bdb.uz', 'facebook' => '', 'youtube' => '@bdbuz', 'linkedin' => 'bdb-uz',
        ])->assertSessionHasNoErrors();

        $info = CompanyInfo::instance()->fresh();
        $this->assertSame('https://t.me/bdb_uz', $info->telegram);
        $this->assertSame('https://instagram.com/bdb.uz', $info->instagram);
        $this->assertNull($info->facebook);
        $this->assertSame('https://www.youtube.com/@bdbuz', $info->youtube);
        $this->assertSame('https://www.linkedin.com/company/bdb-uz', $info->linkedin);

        $this->actingAs($admin)->from(route('admin.company-info.edit'))->put(route('admin.company-info.update'), [
            'telegram' => 'https://instagram.com/bdb', 'instagram' => 'javascript:alert(1)',
        ])->assertSessionHasErrors(['telegram', 'instagram']);

        $this->assertSame('https://t.me/bdb_uz', CompanyInfo::instance()->fresh()->telegram);

        $this->actingAs($admin)->get(route('admin.company-info.edit'))
            ->assertOk()->assertSee('placeholder="https://t.me/bdb_uz or @bdb_uz"', false);
    }

    public function test_site_shows_old_raw_values_as_working_links_and_hides_invalid_ones(): void
    {
        // Saved before validation existed.
        CompanyInfo::instance()->update(['telegram' => '@bdb_uz', 'instagram' => 'javascript:alert(1)', 'youtube' => 'youtube.com/@bdbuz']);

        foreach (['contact', 'about'] as $page) {
            $this->get(route("site.{$page}", 'ru'))
                ->assertOk()
                ->assertSee('href="https://t.me/bdb_uz"', false)
                ->assertSee('href="https://youtube.com/@bdbuz"', false)
                ->assertDontSee('javascript:alert(1)', false)
                ->assertDontSee('href="@bdb_uz"', false);
        }

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.company-info.edit'))
            ->assertOk()->assertSee('Saved value is not a valid Instagram link');
    }
}
