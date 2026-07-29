<?php

namespace App\Providers;

use App\Models\ArticleCategory;
use App\Models\CompanyInfo;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::composer('site.*', function ($view) {
            $view->with('companyInfo', CompanyInfo::instance());
            $view->with('articleCategories', ArticleCategory::orderBy('sort_order')->get());
        });
    }
}
