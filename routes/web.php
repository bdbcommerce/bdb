<?php

use App\Http\Controllers\Admin\AiInstructionController;
use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ArticleCategoryController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AwaitingReplyController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\ChatConversationController;
use App\Http\Controllers\Admin\ChatLeadController;
use App\Http\Controllers\Admin\ClientRequestController;
use App\Http\Controllers\Admin\ColorThemeController;
use App\Http\Controllers\Admin\CompanyInfoController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IndustryController;
use App\Http\Controllers\Admin\JobApplicationController;
use App\Http\Controllers\Admin\KnowledgeEntryController;
use App\Http\Controllers\Admin\McpSettingController;
use App\Http\Controllers\Admin\SeoSettingController;
use App\Http\Controllers\Admin\TelegramSettingsController;
use App\Http\Controllers\Admin\TelegramUserController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\VacancyController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\Site\AboutController;
use App\Http\Controllers\Site\BrandsController;
use App\Http\Controllers\Site\CareersController;
use App\Http\Controllers\Site\ChatController;
use App\Http\Controllers\Site\ContactController;
use App\Http\Controllers\Site\FaqController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\IndustriesController;
use App\Http\Controllers\Site\NewsController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackPageView;
use Illuminate\Support\Facades\Route;

// AI chat assistant
Route::post('/chat/message', [ChatController::class, 'message'])->middleware('throttle:chat-message')->name('chat.message');
Route::post('/chat/lead', [ChatController::class, 'lead'])->middleware('throttle:chat-lead')->name('chat.lead');

// Telegram bot (same assistant), secured by the X-Telegram-Bot-Api-Secret-Token header
Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');

// Files for search engines and AI assistants (generated; public/robots.txt and sitemap.xml must not exist)
Route::middleware(TrackPageView::class)->group(function () { // crawler visits count in Site analytics
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
    Route::get('/llms.txt', [SeoController::class, 'llms'])->name('seo.llms');
});
Route::get('/{key}.txt', [SeoController::class, 'indexNowKey'])->where('key', '[a-z0-9]{8,64}')->name('seo.indexnow-key');

// MCP server for Claude and other AI clients (Admin → AI Assistant → MCP server; CSRF-exempt, own auth)
Route::match(['get', 'post', 'delete'], '/mcp', McpController::class)->name('mcp');

// Root redirect to default locale
Route::get('/', fn () => redirect('/uz'));

// Public site
Route::prefix('{locale}')
    ->where(['locale' => 'uz|ru|en'])
    ->middleware([SetLocale::class, TrackPageView::class])
    ->name('site.')
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('/about', AboutController::class)->name('about');
        Route::get('/brands', BrandsController::class)->name('brands');
        Route::get('/industries', IndustriesController::class)->name('industries');
        // Customer names are not public: the old Customers page sends visitors to Industries (counts only).
        Route::get('/customers', fn (string $locale) => redirect()->route('site.industries', $locale, 301))->name('customers');
        Route::get('/contact', ContactController::class)->name('contact');
        Route::get('/faq', FaqController::class)->name('faq');
        Route::get('/careers', [CareersController::class, 'index'])->name('careers');
        Route::post('/careers', [CareersController::class, 'apply'])->middleware('throttle:careers')->name('careers.apply');
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

        Route::get('mcp', [McpSettingController::class, 'edit'])->name('mcp.edit');
        Route::put('mcp', [McpSettingController::class, 'update'])->name('mcp.update');
        Route::post('mcp/token', [McpSettingController::class, 'token'])->middleware('throttle:10,1')->name('mcp.token');
        Route::get('analytics', AnalyticsController::class)->name('analytics');
        Route::get('seo', [SeoSettingController::class, 'edit'])->name('seo.edit');
        Route::put('seo', [SeoSettingController::class, 'update'])->name('seo.update');
        Route::post('seo/indexnow', [SeoSettingController::class, 'submitAll'])->middleware('throttle:5,1')->name('seo.indexnow');
        Route::post('seo/refresh', [SeoSettingController::class, 'refresh'])->name('seo.refresh');
        Route::post('color-themes/{color_theme}/activate', [ColorThemeController::class, 'activate'])->name('color-themes.activate');
        Route::resource('color-themes', ColorThemeController::class)->except(['show']);
        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('industries', IndustryController::class)->except(['show']);
        Route::resource('customers', CustomerController::class)->except(['show']);

        // Careers: vacancies on the public page and the CVs sent from it
        Route::resource('vacancies', VacancyController::class)->except(['show']);
        Route::get('job-applications/{jobApplication}/cv', [JobApplicationController::class, 'download'])->name('job-applications.cv');
        Route::resource('job-applications', JobApplicationController::class)->only(['index', 'show', 'update', 'destroy']);

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

        Route::resource('telegram-users', TelegramUserController::class)->only(['index', 'show', 'update']);
        Route::get('awaiting-replies', [AwaitingReplyController::class, 'index'])->name('awaiting-replies.index');
        Route::get('telegram-chats', [ChatConversationController::class, 'index'])->name('telegram-chats.index');
        Route::post('chat-conversations/{chatConversation}/handled', [AwaitingReplyController::class, 'handled'])->name('chat-conversations.handled');
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
