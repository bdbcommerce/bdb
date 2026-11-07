<?php

namespace Tests\Feature;

use App\Models\CompanyInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_block_and_links_link_to_mylabel_by_default_in_every_locale(): void
    {
        foreach (['uz', 'ru', 'en'] as $lc) {
            $html = $this->get("/{$lc}")->assertOk()->getContent();

            $this->assertStringContainsString('id="shop"', $html);
            $this->assertStringContainsString(e(__('site.shop.title', ['site' => 'mylabel.uz'], $lc)), $html);
            $this->assertStringContainsString('href="https://mylabel.uz?utm_source=bdb_site&amp;utm_medium=referral&amp;utm_campaign=home_block"', $html);
            $this->assertStringContainsString('utm_campaign=header', $html);
            $this->assertStringContainsString('utm_campaign=footer', $html);
        }

        $this->get('/en/brands')->assertOk()->assertSee('utm_campaign=brands_block', false);
    }

    public function test_empty_or_invalid_shop_url_hides_everything(): void
    {
        foreach ([null, 'javascript:alert(1)'] as $value) {
            CompanyInfo::instance()->forceFill(['shop_url' => $value])->save();

            $this->get('/en')->assertOk()->assertDontSee('id="shop"', false)->assertDontSee('utm_source=bdb_site', false);
        }
    }

    public function test_admin_can_change_shop_url(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.company-info.update'), ['shop_url' => 'https://shop.example.uz/catalog?x=1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://shop.example.uz/catalog?x=1', CompanyInfo::instance()->fresh()->shop_url);
        $this->get('/en')->assertSee('https://shop.example.uz/catalog?x=1&amp;utm_source=bdb_site', false)->assertSee('shop.example.uz');

        foreach (['not a url', 'http://localhost', 'ftp://mylabel.uz'] as $bad) {
            $this->actingAs($admin)->put(route('admin.company-info.update'), ['shop_url' => $bad])
                ->assertSessionHasErrors('shop_url');
        }
    }

    public function test_utm_tags_go_before_the_fragment(): void
    {
        $info = new CompanyInfo(['shop_url' => 'https://mylabel.uz/#catalog']);
        $this->assertSame('https://mylabel.uz/?utm_source=bdb_site&utm_medium=referral&utm_campaign=x#catalog', $info->shopUrl('x'));

        $info->shop_url = 'https://mylabel.uz/?a=1#x';
        $this->assertSame('https://mylabel.uz/?a=1&utm_source=bdb_site&utm_medium=referral&utm_campaign=y#x', $info->shopUrl('y'));
    }
}
