<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\CompanyInfo;
use App\Models\Customer;
use App\Models\Industry;

class HomeController extends Controller
{
    public function __invoke()
    {
        $banners    = Banner::active()->get();
        $brands     = Brand::active()->get();
        $industries = Industry::active()
            ->with(['customers' => fn ($q) => $q->active()])
            ->get();
        $articles   = Article::published()->with('category')->limit(3)->get();

        $stats = $this->stats($brands->count(), $industries->count());

        return view('site.home', compact('banners', 'brands', 'industries', 'articles', 'stats'));
    }

    /**
     * Key numbers for the experience band. Uses CompanyInfo fields when the admin
     * has them (founded_year, projects_count), otherwise falls back to live model
     * counts. Zero values are dropped so the band never shows "0 clients".
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function stats(int $brandCount, int $industryCount): array
    {
        $info = CompanyInfo::instance();

        $founded  = (int) $info->getAttribute('founded_year');
        $years    = $founded > 1900 && $founded <= (int) date('Y') ? (int) date('Y') - $founded : 0;
        $projects = (int) $info->getAttribute('projects_count');
        $clients  = Customer::active()->count();

        return array_values(array_filter([
            ['value' => $years,         'label' => 'years'],
            ['value' => $clients,       'label' => 'clients'],
            ['value' => $brandCount,    'label' => 'brands'],
            ['value' => $industryCount, 'label' => 'industries'],
            ['value' => $projects,      'label' => 'projects'],
        ], fn ($s) => $s['value'] > 0));
    }
}
