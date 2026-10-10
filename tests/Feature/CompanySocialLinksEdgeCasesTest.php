<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanySocialLinksEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public static function acceptedLinks(): array
    {
        return [
            'uppercase scheme and host' => ['telegram', 'HTTPS://T.ME/bdb_uz', 'https://t.me/bdb_uz'],
            'trailing slash kept' => ['telegram', 'https://t.me/bdb_uz/', 'https://t.me/bdb_uz/'],
            'fragment dropped' => ['telegram', 'https://t.me/bdb_uz#x', 'https://t.me/bdb_uz'],
            'port dropped' => ['telegram', 'https://t.me:8443/bdb_uz', 'https://t.me/bdb_uz'],
            'telegram plus invite' => ['telegram', 't.me/+AbCdEf123', 'https://t.me/+AbCdEf123'],
            'telegram joinchat' => ['telegram', 't.me/joinchat/AbCd', 'https://t.me/joinchat/AbCd'],
            'youtu.be short link' => ['youtube', 'youtu.be/dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ'],
            'youtube query kept' => ['youtube', 'youtube.com/watch?v=a&list=b', 'https://youtube.com/watch?v=a&list=b'],
            'facebook profile.php id' => ['facebook', 'facebook.com/profile.php?id=100012345', 'https://facebook.com/profile.php?id=100012345'],
            'fb.com' => ['facebook', 'fb.com/bdb', 'https://fb.com/bdb'],
            'linkedin personal profile' => ['linkedin', 'linkedin.com/in/john-doe', 'https://linkedin.com/in/john-doe'],
            'encoded chars kept' => ['telegram', 'https://t.me/%3Cscript%3E', 'https://t.me/%3Cscript%3E'],
        ];
    }

    #[DataProvider('acceptedLinks')]
    public function test_accepted_links(string $network, string $input, string $expected): void
    {
        $this->assertSame($expected, CompanyInfo::socialUrl($network, $input));
    }

    public static function rejectedLinks(): array
    {
        return [
            'cyrillic host' => ['telegram', 'https://т.me/bdb'],
            'punycode host' => ['telegram', 'https://xn--e1a.me/bdb'],
            'cyrillic handle' => ['telegram', '@бдб'],
            'protocol relative' => ['telegram', '//t.me/bdb'],
            'tg scheme' => ['telegram', 'tg://resolve?domain=bdb'],
            'uppercase javascript' => ['telegram', 'JavaScript:alert(1)'],
            'javascript with host' => ['telegram', 'javascript://t.me/%0aalert(1)'],
            'userinfo to other host' => ['telegram', 'https://t.me@evil.com'],
            'backslash userinfo' => ['telegram', 'https://evil.com\\@t.me/x'],
            'trailing dot host' => ['telegram', 'https://t.me./bdb'],
            'newline' => ['telegram', "t.me/bdb\nx"],
            'handle over 100 chars' => ['telegram', '@'.str_repeat('a', 101)],
        ];
    }

    #[DataProvider('rejectedLinks')]
    public function test_rejected_links(string $network, string $input): void
    {
        $this->assertNull(CompanyInfo::socialUrl($network, $input));
    }

    public function test_array_input_for_social_field_returns_validation_error_not_500(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->from(route('admin.company-info.edit'))
            ->put(route('admin.company-info.update'), ['telegram' => ['x']])
            ->assertRedirect(route('admin.company-info.edit'))
            ->assertSessionHasErrors('telegram');
    }

    public function test_input_longer_than_255_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['telegram' => 't.me/'.str_repeat('a', 251)])
            ->assertSessionHasErrors('telegram');
    }

    public function test_normalized_value_never_exceeds_column_length(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // 255 chars passes max:255, then "https://" is prepended.
        $this->actingAs($admin)->put(route('admin.company-info.update'), ['telegram' => 't.me/'.str_repeat('a', 250)]);

        $this->assertLessThanOrEqual(255, strlen((string) CompanyInfo::instance()->fresh()->telegram));
    }

    public function test_other_company_fields_still_save_and_invalid_social_keeps_old_input(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.company-info.update'), [
            'address_uz' => 'Toshkent, Amir Temur ko‘chasi', 'address_ru' => 'Ташкент', 'address_en' => 'Tashkent',
            'slogan_en' => 'Slogan', 'about_ru' => 'О компании',
            'phone' => '+998 71 000 00 00', 'email' => 'info@example.uz',
            'telegram' => '@bdb_uz', 'map_embed' => '<iframe src="https://maps.example"></iframe>',
            'google_analytics' => 'G-TEST', 'yandex_metrika' => '123',
        ])->assertSessionHasNoErrors();

        $info = CompanyInfo::instance()->fresh();
        $this->assertSame('Toshkent, Amir Temur ko‘chasi', $info->getTranslation('address', 'uz'));
        $this->assertSame('Ташкент', $info->getTranslation('address', 'ru'));
        $this->assertSame('О компании', $info->getTranslation('about', 'ru'));
        $this->assertSame('+998 71 000 00 00', $info->phone);
        $this->assertSame('info@example.uz', $info->email);
        $this->assertSame('G-TEST', $info->google_analytics);
        $this->assertSame('123', $info->yandex_metrika);
        $this->assertSame('https://t.me/bdb_uz', $info->telegram);

        $this->actingAs($admin)->from(route('admin.company-info.edit'))
            ->put(route('admin.company-info.update'), ['phone' => '+998 99', 'instagram' => 'bdb.uz'])
            ->assertRedirect(route('admin.company-info.edit'));
        // assertSessionHasErrors() is not used here: it replaces the flashed bag, hiding the errors from the next request.

        $this->actingAs($admin)->get(route('admin.company-info.edit'))
            ->assertOk()
            ->assertSee('value="bdb.uz"', false)
            ->assertSee('id="instagram-error"', false)
            ->assertSee('Enter it like', false);
        $this->assertSame('+998 71 000 00 00', CompanyInfo::instance()->fresh()->phone);
    }

    public function test_layout_json_ld_same_as_and_footer_use_normalized_links_in_all_locales(): void
    {
        CompanyInfo::instance()->update([
            'telegram' => 't.me/bdb_uz', 'instagram' => '@bdb.uz', 'facebook' => 'javascript:alert(1)',
            'youtube' => '"><script>alert(1)</script>', 'linkedin' => 'https://evil.com/linkedin.com',
        ]);

        foreach (['uz', 'ru', 'en'] as $locale) {
            $html = $this->get(route('site.home', $locale))->assertOk()->getContent();

            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $ld = json_decode($m[1] ?? '', true);
            $this->assertSame(['https://t.me/bdb_uz', 'https://www.instagram.com/bdb.uz'], $ld['sameAs'] ?? null, $locale);

            $this->assertStringContainsString('href="https://t.me/bdb_uz"', $html);
            $this->assertStringNotContainsString('javascript:alert', $html);
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
            $this->assertStringNotContainsString('evil.com', $html);
            $this->assertStringNotContainsString('href="t.me/', $html);
        }
    }

    public function test_no_same_as_when_no_valid_socials(): void
    {
        CompanyInfo::instance()->update(['telegram' => 'not a link', 'instagram' => null]);

        $html = $this->get(route('site.home', 'en'))->assertOk()->getContent();
        $this->assertStringNotContainsString('sameAs', $html);
    }
}
