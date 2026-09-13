<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

// Public Event Routes
Route::get('/', [EventController::class, 'index'])->name('events.index');
Route::get('/events/{slug}', [EventController::class, 'show'])->name('events.show');

// Locale Switcher
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['lv', 'en', 'ru'])) {
        session(['locale' => $locale]);
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

    // Events Data Grid (Excel style review)
    Route::get('/admin/events', [\App\Http\Controllers\Admin\EventController::class, 'index'])->name('admin.events.index');

    // User & Permissions Registry
    Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
    Route::post('/admin/users/{user}/role', [AdminUserController::class, 'updateRole'])->name('admin.users.role');
    Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
});
