<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Industry;

class HomeController extends Controller
{
    public function __invoke()
    {
        $banners    = Banner::active()->get();
        $brands     = Brand::active()->get();
        $industries = Industry::active()->get();
        $articles   = Article::published()->with('category')->limit(3)->get();

        return view('site.home', compact('banners', 'brands', 'industries', 'articles'));
    }
}
