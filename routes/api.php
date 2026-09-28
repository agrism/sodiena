<?php

use App\Http\Controllers\Api\EventApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group and protected with Bearer token.
|
*/

Route::middleware('auth.bearer')->group(function () {
    // API Version 1
    Route::prefix('v1')->group(function () {
        Route::get('/events', [EventApiController::class, 'index'])->name('api.v1.events.index');
        Route::get('/events/promoted', [EventApiController::class, 'promoted'])->name('api.v1.events.promoted');
        Route::get('/events/{id}', [EventApiController::class, 'show'])->name('api.v1.events.show');
    });

    // Default unversioned aliases
    Route::get('/events', [EventApiController::class, 'index'])->name('api.events.index');
    Route::get('/events/promoted', [EventApiController::class, 'promoted'])->name('api.events.promoted');
    Route::get('/events/{id}', [EventApiController::class, 'show'])->name('api.events.show');
});
