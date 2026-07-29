<?php

use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CompanyInfoController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IndustryController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\BrandsController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\CustomersController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\IndustriesController;
use App\Http\Controllers\Site\NewsController;
use Illuminate\Support\Facades\Route;

// Root redirect to default locale
Route::get('/', fn() => redirect('/uz'));

// Public site
Route::prefix('{locale}')
    ->where(['locale' => 'uz|ru|en'])
    ->middleware(\App\Http\Middleware\SetLocale::class)
    ->name('site.')
    ->group(function () {
        Route::get('/',           HomeController::class)->name('home');
        Route::get('/about',      AboutController::class)->name('about');
        Route::get('/brands',     BrandsController::class)->name('brands');
        Route::get('/industries', IndustriesController::class)->name('industries');
        Route::get('/customers',  CustomersController::class)->name('customers');
        Route::get('/contact',    ContactController::class)->name('contact');
        Route::get('/news',       [NewsController::class, 'index'])->name('news');
        Route::get('/news/{categorySlug}',        [NewsController::class, 'category'])->name('news.category');
        Route::get('/news/{categorySlug}/{slug}', [NewsController::class, 'show'])->name('news.show');
    });

// Admin auth
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.post');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware(\App\Http\Middleware\AdminMiddleware::class)->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('company-info', [CompanyInfoController::class, 'edit'])->name('company-info.edit');
        Route::put('company-info', [CompanyInfoController::class, 'update'])->name('company-info.update');
        Route::post('company-info/sitemap', [CompanyInfoController::class, 'generateSitemap'])->name('company-info.sitemap');

        Route::post('upload/image', [UploadController::class, 'image'])->name('upload.image');

        Route::resource('banners', BannerController::class)->except(['show']);
        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('industries', IndustryController::class)->except(['show']);
        Route::resource('customers', CustomerController::class)->except(['show']);

        // article-categories: store/update handled by Livewire component
        Route::resource('article-categories', ArticleCategoryController::class)->only(['index', 'create', 'edit', 'destroy']);

        // articles: store/update handled by Livewire component
        Route::resource('articles', ArticleController::class)->only(['index', 'create', 'edit', 'destroy']);
    });
});
