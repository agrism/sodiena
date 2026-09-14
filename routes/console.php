<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('events:scrape --all')
    ->dailyAt('04:00')
    ->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('events:prune-past')
    ->dailyAt('00:00')
    ->withoutOverlapping();

\Illuminate\Support\Facades\Schedule::command('events:sync-images')
    ->dailyAt('04:30')
    ->withoutOverlapping();

