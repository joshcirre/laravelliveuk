<?php

use Illuminate\Support\Facades\Route;

Route::match(['GET', 'HEAD'], '/wake', fn () => response()->noContent()->withHeaders([
    'Access-Control-Allow-Origin' => '*',
    'Cache-Control' => 'no-store, max-age=0',
    'Timing-Allow-Origin' => '*',
]))->name('wake');

Route::livewire('/', 'pages::game')->name('home');
