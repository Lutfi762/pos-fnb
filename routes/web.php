<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\ScreenLock;
use App\Http\Controllers\SyncDraftOrderController;
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

/*
| The kitchen display. Deliberately outside the screen lock: the pass is a
| wall-mounted screen nobody stands at, and a cook with wet hands should never
| have to type a PIN to read what to cook. Auth and outlet still apply.
*/
Route::middleware(['auth', 'verified', 'outlet'])->group(function () {
    Route::livewire('kitchen', 'pages::kitchen.index')->name('kitchen.index');
    Route::livewire('kitchen/{order}/docket', 'pages::kitchen.docket')->name('kitchen.docket');
});

Route::middleware(['auth', 'verified', 'outlet', 'unlocked'])->group(function () {
    Route::post('sync/draft-orders', SyncDraftOrderController::class)->name('sync.draft-orders.store');

    // The landing page after login is the sales report. Cashiers may open it
    // too: the page itself hides the cost and margin figures from them.
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // Internal reference page for the design system.
    Route::view('design-system', 'pages.design-system')->name('design-system');

    // The till itself. Every role that can log in can ring up a sale.
    Route::livewire('pos', 'pages::pos.index')->name('pos.index');
    Route::livewire('pos/shift', 'pages::pos.shift')->name('pos.shift');
    Route::livewire('pos/{order}/pay', 'pages::pos.payment')->name('pos.payment');
    Route::livewire('pos/{order}/receipt', 'pages::pos.receipt')->name('pos.receipt');

    // The stockroom. Warehouse staff live here; managers pass through.
    Route::middleware('role:owner,manager,warehouse')->group(function () {
        Route::livewire('stock', 'pages::stock.index')->name('stock.index');
        Route::livewire('purchases', 'pages::purchases.index')->name('purchases.index');
        Route::livewire('purchases/create', 'pages::purchases.edit')->name('purchases.create');
        Route::livewire('purchases/{purchaseOrder}', 'pages::purchases.show')->name('purchases.show');
        Route::livewire('purchases/{purchaseOrder}/edit', 'pages::purchases.edit')->name('purchases.edit');
    });

    // Editing the menu is a management job, not a cashier one.
    Route::middleware('role:owner,manager')->group(function () {
        Route::livewire('catalog', 'pages::catalog.index')->name('catalog.index');
        Route::livewire('catalog/create', 'pages::catalog.edit')->name('catalog.create');
        Route::livewire('catalog/{product}/edit', 'pages::catalog.edit')->name('catalog.edit');
    });
});

require __DIR__.'/settings.php';
