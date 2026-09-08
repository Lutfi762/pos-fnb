<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Internal reference page for the design system.
    Route::view('design-system', 'pages.design-system')->name('design-system');
});

require __DIR__.'/settings.php';
