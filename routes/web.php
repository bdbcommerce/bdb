<?php

use App\Http\Controllers\Admin\AiInstructionController;
use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\ChatConversationController;
use App\Http\Controllers\Admin\ChatLeadController;
use App\Http\Controllers\Admin\ClientRequestController;
use App\Http\Controllers\Admin\CompanyInfoController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IndustryController;
use App\Http\Controllers\Admin\KnowledgeEntryController;
use App\Http\Controllers\Admin\TelegramSettingsController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\BrandsController;
use App\Http\Controllers\Site\ChatController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\CustomersController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\IndustriesController;
use App\Http\Controllers\Site\NewsController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

// AI chat assistant
Route::post('/chat/message', [ChatController::class, 'message'])->middleware('throttle:20,1')->name('chat.message');
Route::post('/chat/lead', [ChatController::class, 'lead'])->middleware('throttle:5,1')->name('chat.lead');

// Telegram bot (same assistant), secured by the X-Telegram-Bot-Api-Secret-Token header
Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

// Root redirect to default locale
Route::get('/', fn () => redirect('/uz'));

// Public site
Route::prefix('{locale}')
    ->where(['locale' => 'uz|ru|en'])
    ->middleware(SetLocale::class)
    ->name('site.')
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/about', AboutController::class)->name('about');
        Route::get('/brands', BrandsController::class)->name('brands');
        Route::get('/industries', IndustriesController::class)->name('industries');
        Route::get('/customers', CustomersController::class)->name('customers');
        Route::get('/contact', ContactController::class)->name('contact');
        Route::get('/news', [NewsController::class, 'index'])->name('news');
        Route::get('/news/{categorySlug}', [NewsController::class, 'category'])->name('news.category');
        Route::get('/news/{categorySlug}/{slug}', [NewsController::class, 'show'])->name('news.show');
    });

// Admin auth
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.post');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware(AdminMiddleware::class)->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('company-info', [CompanyInfoController::class, 'edit'])->name('company-info.edit');
        Route::put('company-info', [CompanyInfoController::class, 'update'])->name('company-info.update');
        Route::post('company-info/sitemap', [CompanyInfoController::class, 'generateSitemap'])->name('company-info.sitemap');

        Route::post('upload/image', [UploadController::class, 'image'])->name('upload.image');

        Route::resource('banners', BannerController::class)->except(['show']);
        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('industries', IndustryController::class)->except(['show']);
        Route::resource('customers', CustomerController::class)->except(['show']);

        Route::resource('knowledge', KnowledgeEntryController::class)->except(['show']);
        // AI settings: models per task, tried by priority
        Route::resource('ai-models', AiModelController::class)->except(['show']);
        Route::post('ai-models/{aiModel}/test', [AiModelController::class, 'test'])->middleware('throttle:20,1')->name('ai-models.test');
        Route::post('ai-models/{aiModel}/resume', [AiModelController::class, 'resume'])->name('ai-models.resume');

        Route::get('ai-instructions/prompt', [AiInstructionController::class, 'prompt'])->name('ai-instructions.prompt');
        Route::post('ai-instructions/main/reset', [AiInstructionController::class, 'reset'])->name('ai-instructions.reset');
        Route::resource('ai-instructions', AiInstructionController::class)->except(['show']);

        // Client requests: customer questions grouped by AI topic, answered with knowledge entries
        Route::get('client-requests', [ClientRequestController::class, 'index'])->name('client-requests.index');
        Route::get('client-requests/{clientRequest}', [ClientRequestController::class, 'show'])->name('client-requests.show');
        Route::post('client-requests/{clientRequest}/link', [ClientRequestController::class, 'link'])->name('client-requests.link');
        Route::post('client-requests/{clientRequest}/dismiss', [ClientRequestController::class, 'dismiss'])->name('client-requests.dismiss');
        Route::post('client-requests/{clientRequest}/notify', [ClientRequestController::class, 'notify'])->middleware('throttle:10,1')->name('client-requests.notify');
        Route::resource('chat-leads', ChatLeadController::class)->only(['index', 'update', 'destroy']);
        Route::resource('chat-conversations', ChatConversationController::class)->only(['index', 'show', 'destroy']);
        Route::post('chat-conversations/{chatConversation}/reply', [ChatConversationController::class, 'reply'])->middleware('throttle:30,1')->name('chat-conversations.reply');
        Route::post('chat-conversations/{chatConversation}/suggest', [ChatConversationController::class, 'suggest'])->middleware('throttle:20,1')->name('chat-conversations.suggest');

        Route::get('telegram', [TelegramSettingsController::class, 'edit'])->name('telegram.edit');
        Route::get('telegram/media/{fileId}', [ChatConversationController::class, 'media'])->where('fileId', '[A-Za-z0-9_-]{10,255}')->name('telegram.media');
        Route::post('telegram/connect', [TelegramSettingsController::class, 'connect'])->name('telegram.connect');
        Route::post('telegram/disconnect', [TelegramSettingsController::class, 'disconnect'])->name('telegram.disconnect');
        Route::post('telegram/test', [TelegramSettingsController::class, 'test'])->name('telegram.test');

        // article-categories: store/update handled by Livewire component
        Route::resource('article-categories', ArticleCategoryController::class)->only(['index', 'create', 'edit', 'destroy']);

        // articles: store/update handled by Livewire component
        Route::resource('articles', ArticleController::class)->only(['index', 'create', 'edit', 'destroy']);
    });
});
