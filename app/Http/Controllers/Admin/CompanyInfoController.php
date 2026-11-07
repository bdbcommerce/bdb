<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyInfo;
use App\Models\SeoSetting;
use Illuminate\Http\Request;

class CompanyInfoController extends Controller
{
    public function edit()
    {
        $info = CompanyInfo::instance();

        return view('admin.company-info.edit', compact('info'));
    }

    public function update(Request $request)
    {
        $socialRules = [];
        foreach (CompanyInfo::SOCIALS as $network => $config) {
            $socialRules[$network] = ['bail', 'nullable', 'string', 'max:255', function ($attribute, $value, $fail) use ($network, $config) {
                if (! is_string($value) || CompanyInfo::socialUrl($network, $value) === null) {
                    $fail("The {$config['label']} link is not valid. Enter it like {$config['example']}.");
                }
            }];
        }

        $request->validate([
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'telegram_bot' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) {
                if (CompanyInfo::botUsername($value) === null) {
                    $fail('Enter the bot username like @bdb_uz_bot (it must end with “bot”) or its t.me link.');
                }
            }],
            'shop_url' => ['nullable', 'url:http,https', 'max:255', function ($attribute, $value, $fail) {
                if (! CompanyInfo::isValidShopUrl($value)) {
                    $fail('Enter the public shop address, like https://mylabel.uz.');
                }
            }],
            'map_embed' => ['nullable', 'string', 'max:4000', function ($attribute, $value, $fail) {
                if (CompanyInfo::isYandexShortLink($value)) {
                    $fail('Short Yandex links (yandex.uz/maps/-/…) can\'t be shown on the site. Open the link, then copy the full address from the browser bar, or use Share → Embed code.');
                } elseif (CompanyInfo::yandexMapQuery($value) === null) {
                    $fail('Paste a Yandex Maps link (yandex.uz/maps/…), the Yandex embed code, or coordinates like 41.311081, 69.240562.');
                }
            }],
        ] + $socialRules);

        // Store every link as a full https URL, whatever form the admin typed.
        $socials = [];
        foreach (array_keys(CompanyInfo::SOCIALS) as $network) {
            $socials[$network] = CompanyInfo::socialUrl($network, $request->input($network));
        }

        $info = CompanyInfo::instance();
        $info->update([
            'address' => [
                'uz' => $request->input('address_uz'),
                'ru' => $request->input('address_ru'),
                'en' => $request->input('address_en'),
            ],
            'slogan' => [
                'uz' => $request->input('slogan_uz'),
                'ru' => $request->input('slogan_ru'),
                'en' => $request->input('slogan_en'),
            ],
            'about' => [
                'uz' => $request->input('about_uz'),
                'ru' => $request->input('about_ru'),
                'en' => $request->input('about_en'),
            ],
            'phone' => $request->input('phone'),
            'email' => $request->input('email'),
            'shop_url' => $request->input('shop_url'),
            'telegram_bot' => CompanyInfo::botUsername($request->input('telegram_bot')),
            ...$socials,
            'map_embed' => $request->input('map_embed'),
            'google_analytics' => $request->input('google_analytics'),
            'yandex_metrika' => $request->input('yandex_metrika'),
        ]);

        return back()->with('success', 'Company information updated.');
    }

    public function generateSitemap()
    {
        SeoSetting::flush();

        return back()->with('success', 'sitemap.xml is rebuilt from the current content: '.url('/sitemap.xml'));
    }
}
