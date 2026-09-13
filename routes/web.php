<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::get('/', [EventController::class, 'index'])->name('events.index');
Route::get('/events/{slug}', [EventController::class, 'show'])->name('events.show');
Route::get('/sources', [EventController::class, 'sources'])->name('events.sources');
Route::post('/sources/{source}/scrape', [EventController::class, 'triggerScrape'])->name('events.scrape');

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['lv', 'en', 'ru'])) {
        session(['locale' => $locale]);
    }
    return redirect()->back();
})->name('locale.switch');
