<?php

namespace App\Console\Commands;

use App\Models\SeoSetting;
use App\Services\Seo\SeoFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** sitemap.xml is served live from /sitemap.xml; this rebuilds it now and reports the count. */
#[Signature('sitemap:generate')]
#[Description('Rebuild the live sitemap.xml (served at /sitemap.xml) from the current content')]
class GenerateSitemap extends Command
{
    public function handle(SeoFiles $files): void
    {
        SeoSetting::flush();
        if (file_exists(public_path('sitemap.xml'))) {
            $this->warn('public/sitemap.xml exists and hides the live sitemap — delete it.');
        }

        $this->info('sitemap.xml rebuilt with '.count($files->urls()).' URLs: '.url('/sitemap.xml'));
    }
}
