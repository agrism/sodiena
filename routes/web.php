<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\UserEventController;
use App\Services\LocaleService;
use Illuminate\Support\Facades\Route;

// SEO & Sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// English & Russian Localized Public Routes (/en, /en/events/{slug}, /ru, /ru/events/{slug})
Route::prefix('{locale}')
    ->whereIn('locale', ['en', 'ru'])
    ->group(function () {
        Route::get('/', [EventController::class, 'index'])->name('localized.events.index');

        // Localized User Event Creation & Management
        Route::middleware('auth')->group(function () {
            Route::get('/events/create', [UserEventController::class, 'create'])->name('localized.events.create');
            Route::post('/events', [UserEventController::class, 'store'])->name('localized.events.store');
            Route::get('/my-events', [UserEventController::class, 'myEvents'])->name('localized.events.my');
            Route::get('/events/{event}/edit', [UserEventController::class, 'edit'])->name('localized.events.edit');
            Route::put('/events/{event}', [UserEventController::class, 'update'])->name('localized.events.update');
            Route::delete('/events/{event}', [UserEventController::class, 'destroy'])->name('localized.events.destroy');
        });

        Route::get('/events/{slug}', [EventController::class, 'show'])->name('localized.events.show');
    });

// Redirect /lv and /lv/{any} 301 to canonical non-prefixed Latvian URL
Route::get('/lv', function () {
    return redirect(request()->getQueryString() ? '/?' . request()->getQueryString() : '/', 301);
});
Route::get('/lv/{any}', function ($any) {
    return redirect('/' . $any . (request()->getQueryString() ? '?' . request()->getQueryString() : ''), 301);
})->where('any', '.*');

// User Event Creation & Management (Protected by auth)
Route::middleware('auth')->group(function () {
    Route::get('/events/create', [UserEventController::class, 'create'])->name('events.create');
    Route::post('/events', [UserEventController::class, 'store'])->name('events.store');
    Route::get('/my-events', [UserEventController::class, 'myEvents'])->name('events.my');
    Route::get('/events/{event}/edit', [UserEventController::class, 'edit'])->name('events.edit');
    Route::put('/events/{event}', [UserEventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [UserEventController::class, 'destroy'])->name('events.destroy');
});

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
    // Sources & Scraper management (Admin Sector)
    Route::get('/admin/sources', [\App\Http\Controllers\Admin\SourceController::class, 'index'])->name('admin.sources.index');
    Route::post('/admin/sources/{source}/scrape', [\App\Http\Controllers\Admin\SourceController::class, 'scrape'])->name('admin.sources.scrape');
    Route::post('/admin/sources/sync-registry', [\App\Http\Controllers\Admin\SourceController::class, 'syncRegistry'])->name('admin.sources.sync-registry');

    // System synchronization tools
    Route::post('/admin/tools/sync-translations', [\App\Http\Controllers\Admin\SourceController::class, 'syncTranslations'])->name('admin.tools.sync-translations');
    Route::post('/admin/tools/sync-images', [\App\Http\Controllers\Admin\SourceController::class, 'syncImages'])->name('admin.tools.sync-images');
    Route::post('/admin/tools/refresh-sitemap', [\App\Http\Controllers\Admin\SourceController::class, 'refreshSitemap'])->name('admin.tools.refresh-sitemap');

    // Legacy redirects / aliases to ensure backwards compatibility
    Route::get('/sources', function (\Illuminate\Http\Request $request) {
        return redirect()->route('admin.sources.index', $request->query());
    })->name('events.sources');
    Route::post('/sources/{source}/scrape', [\App\Http\Controllers\Admin\SourceController::class, 'scrape'])->name('events.scrape');

    // Events Data Grid (Excel style review) & Publication Management
    Route::get('/admin/events', [\App\Http\Controllers\Admin\EventController::class, 'index'])->name('admin.events.index');
    Route::get('/admin/unpublished', [\App\Http\Controllers\Admin\EventController::class, 'unpublished'])->name('admin.events.unpublished');
    Route::get('/admin/unpublished/list', [\App\Http\Controllers\Admin\EventController::class, 'unpublishedList'])->name('admin.events.unpublished.list');
    Route::post('/admin/events/{event}/toggle-publish', [\App\Http\Controllers\Admin\EventController::class, 'togglePublish'])->name('admin.events.toggle-publish');
    Route::post('/admin/events/{event}/toggle-promoted', [\App\Http\Controllers\Admin\EventController::class, 'togglePromoted'])->name('admin.events.toggle-promoted');
    Route::post('/admin/events/{event}/category', [\App\Http\Controllers\Admin\EventController::class, 'updateCategory'])->name('admin.events.update-category');
    Route::post('/admin/events/bulk-publish', [\App\Http\Controllers\Admin\EventController::class, 'bulkPublish'])->name('admin.events.bulk-publish');

    // User & Permissions Registry
    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
    Route::post('/admin/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('admin.users.role');
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
});
