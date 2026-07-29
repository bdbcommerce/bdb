<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Industry;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'brands' => Brand::count(),
            'industries' => Industry::count(),
            'customers' => Customer::count(),
            'articles' => Article::count(),
            'published_articles' => Article::where('is_published', true)->count(),
            'banners' => Banner::count(),
        ];

        return view('admin.dashboard', compact('stats'));
    }
}
