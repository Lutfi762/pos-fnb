<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\ScreenLock;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Shared devices: language follows the session, not the account.
Route::post('locale/{locale}', function (string $locale) {
    session()->put('locale', $locale);

    return back();
})->whereIn('locale', SetLocale::SUPPORTED)->name('locale.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('outlet', 'pages::outlet.select')->name('outlet.select');

    Route::post('outlet/leave', function (CurrentOutlet $current) {
        $current->forget();

        return redirect()->route('outlet.select');
    })->name('outlet.leave');

    Route::livewire('lock', 'pages::lock')->middleware('outlet')->name('lock');

    Route::post('lock', function (ScreenLock $lock) {
        $lock->lock();

        return redirect()->route('lock');
    })->middleware('outlet')->name('lock.store');
});

Route::middleware(['auth', 'verified', 'outlet', 'unlocked'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Internal reference page for the design system.
    Route::view('design-system', 'pages.design-system')->name('design-system');

    // The till itself. Every role that can log in can ring up a sale.
    Route::livewire('pos', 'pages::pos.index')->name('pos.index');

    // Editing the menu is a management job, not a cashier one.
    Route::middleware('role:owner,manager')->group(function () {
        Route::livewire('catalog', 'pages::catalog.index')->name('catalog.index');
        Route::livewire('catalog/create', 'pages::catalog.edit')->name('catalog.create');
        Route::livewire('catalog/{product}/edit', 'pages::catalog.edit')->name('catalog.edit');
    });
});

require __DIR__.'/settings.php';
