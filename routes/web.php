<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SitemapController;
use App\Services\LocaleService;
use Illuminate\Support\Facades\Route;

// SEO & Sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// English & Russian Localized Public Routes (/en, /en/events/{slug}, /ru, /ru/events/{slug})
Route::prefix('{locale}')
    ->whereIn('locale', ['en', 'ru'])
    ->group(function () {
        Route::get('/', [EventController::class, 'index'])->name('localized.events.index');
        Route::get('/events/{slug}', [EventController::class, 'show'])->name('localized.events.show');
    });

// Redirect /lv and /lv/{any} 301 to canonical non-prefixed Latvian URL
Route::get('/lv', function () {
    return redirect(request()->getQueryString() ? '/?' . request()->getQueryString() : '/', 301);
});
Route::get('/lv/{any}', function ($any) {
    return redirect('/' . $any . (request()->getQueryString() ? '?' . request()->getQueryString() : ''), 301);
})->where('any', '.*');

// Default Latvian Public Routes (/, /events/{slug})
Route::get('/', [EventController::class, 'index'])->name('events.index');
Route::get('/events/{slug}', [EventController::class, 'show'])->name('events.show');

// Legacy /locale/{locale} redirect helper
Route::get('/locale/{locale}', function (string $locale) {
    if (LocaleService::isSupported($locale)) {
        return redirect(LocaleService::url($locale, url()->previous()));
    }
    return redirect()->back();
})->name('locale.switch');

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Administrator Routes (Protected by role:admin)
Route::middleware(['auth', 'role:admin'])->group(function () {
    // Sources & Scraper management
    Route::get('/sources', [EventController::class, 'sources'])->name('events.sources');
    Route::get('/admin/sources', [EventController::class, 'sources']);
    Route::post('/sources/{source}/scrape', [EventController::class, 'triggerScrape'])->name('events.scrape');

    // Events Data Grid (Excel style review) & Publication Management
    Route::get('/admin/events', [\App\Http\Controllers\Admin\EventController::class, 'index'])->name('admin.events.index');
    Route::get('/admin/unpublished', [\App\Http\Controllers\Admin\EventController::class, 'unpublished'])->name('admin.events.unpublished');
    Route::get('/admin/unpublished/list', [\App\Http\Controllers\Admin\EventController::class, 'unpublishedList'])->name('admin.events.unpublished.list');
    Route::post('/admin/events/{event}/toggle-publish', [\App\Http\Controllers\Admin\EventController::class, 'togglePublish'])->name('admin.events.toggle-publish');
    Route::post('/admin/events/{event}/category', [\App\Http\Controllers\Admin\EventController::class, 'updateCategory'])->name('admin.events.update-category');
    Route::post('/admin/events/bulk-publish', [\App\Http\Controllers\Admin\EventController::class, 'bulkPublish'])->name('admin.events.bulk-publish');

    // User & Permissions Registry
    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
    Route::post('/admin/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('admin.users.role');
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
});
