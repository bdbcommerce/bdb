<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\SeoSetting;
use App\Services\Seo\SeoFiles;

/** robots.txt, sitemap.xml, llms.txt and the IndexNow key file, generated for the live address. */
class SeoController extends Controller
{
    public function robots(SeoFiles $files)
    {
        return response($files->robots(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(SeoFiles $files)
    {
        return response($files->sitemap(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function llms(SeoFiles $files)
    {
        abort_unless(SeoSetting::current()->llms_enabled, 404);

        return response($files->llms(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function indexNowKey(string $key)
    {
        $settings = SeoSetting::current();
        abort_unless($settings->indexnow_enabled && hash_equals($settings->indexNowKey(), $key), 404);

        return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
