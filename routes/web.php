<?php

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\ScreenLock;
use App\Http\Controllers\CatalogImportTemplateController;
use App\Http\Controllers\GatewayWebhookController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\SyncOperationController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// The payment gateway reporting a paid QRIS code. No session, no CSRF: the
// request proves itself with the gateway's signature.
Route::post('webhooks/payments/{provider}', GatewayWebhookController::class)
    ->middleware('throttle:240,1')
    ->name('webhooks.payments');

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
    // The offline till and the data it rings up with.
    Route::get('pos/offline', [OfflineController::class, 'terminal'])->name('pos.offline');
    Route::get('pos/offline-data', [OfflineController::class, 'snapshot'])->name('pos.offline-data');
    Route::get('sync/session', [OfflineController::class, 'session'])->name('sync.session');

    // Where a manager clears commands a terminal could not apply.
    Route::livewire('sync', 'pages::sync.index')->middleware('role:owner,manager')->name('sync.index');

    // The offline outbox. One general endpoint, plus the three the first
    // offline release used, kept so terminals holding old commands can flush.
    Route::post('sync/operations', SyncOperationController::class)->name('sync.operations.store');
    Route::post('sync/draft-orders', SyncOperationController::class)->defaults('command', 'order.saved')->name('sync.draft-orders.store');
    Route::post('sync/shifts', SyncOperationController::class)->defaults('command', 'shift')->name('sync.shifts.store');
    Route::post('sync/cash-payments', SyncOperationController::class)->defaults('command', 'payment.recorded')->name('sync.cash-payments.store');

    // The landing page after login: how today is going. Every role sees it;
    // the page itself hides cost, margin, and staff figures from cashiers.
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // The sales report for any stretch of days, same rule on cost figures.
    Route::livewire('reports', 'pages::reports.index')->name('reports.index');

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

    // Waste is logged where it happens, which includes the kitchen.
    Route::livewire('stock/waste', 'pages::stock.waste')
        ->middleware('role:owner,manager,warehouse,kitchen')
        ->name('stock.waste');

    // Editing the menu is a management job, not a cashier one.
    Route::middleware('role:owner,manager')->group(function () {
        Route::livewire('payments/review', 'pages::payments.review')->name('payments.review');
        Route::livewire('outlet/settings', 'pages::outlet.settings')->name('outlet.settings');
        Route::get('payments/{payment}/proof', PaymentProofController::class)->name('payments.proof');
        Route::livewire('catalog', 'pages::catalog.index')->name('catalog.index');
        Route::get('catalog/import/template', CatalogImportTemplateController::class)->name('catalog.import.template');
        Route::livewire('catalog/create', 'pages::catalog.edit')->name('catalog.create');
        Route::livewire('catalog/{product}/edit', 'pages::catalog.edit')->name('catalog.edit');
    });
});

require __DIR__.'/settings.php';
