<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CompanyInfoController extends Controller
{
    public function edit()
    {
        $info = CompanyInfo::instance();

        return view('admin.company-info.edit', compact('info'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
        ]);

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
            'telegram' => $request->input('telegram'),
            'instagram' => $request->input('instagram'),
            'facebook' => $request->input('facebook'),
            'youtube' => $request->input('youtube'),
            'linkedin' => $request->input('linkedin'),
            'map_embed' => $request->input('map_embed'),
            'google_analytics' => $request->input('google_analytics'),
            'yandex_metrika' => $request->input('yandex_metrika'),
        ]);

        return back()->with('success', 'Company information updated.');
    }

    public function generateSitemap()
    {
        Artisan::call('sitemap:generate');

        return back()->with('success', 'sitemap.xml generated successfully.');
    }
}
