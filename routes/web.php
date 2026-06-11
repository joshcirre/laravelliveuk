<?php

use Illuminate\Support\Facades\Route;

// The wake probe must measure only the compute restore, so it skips the web
// middleware group entirely — sessions and cookies would drag the database
// (which may itself be waking from sleep) into the measured response time.
Route::match(['GET', 'HEAD'], '/wake', fn () => response()->noContent()->withHeaders([
    'Access-Control-Allow-Origin' => '*',
    'Cache-Control' => 'no-store, max-age=0',
    'Timing-Allow-Origin' => '*',
]))->withoutMiddleware('web')->name('wake');

Route::livewire('/', 'pages::game')->name('home');
