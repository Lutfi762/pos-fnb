<?php

use App\Domain\Sales\Gateway\GatewayException;
use App\Domain\Sales\ReconcileSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Yesterday's QRIS against the gateway's settlement report, before the
// manager's first coffee. Safe to run again by hand for any day.
Artisan::command('meja:reconcile-settlements {date? : Tanggal (YYYY-MM-DD), default kemarin}', function (ReconcileSettlement $reconcile) {
    $given = $this->argument('date');
    $date = is_string($given) && $given !== '' ? CarbonImmutable::parse($given) : CarbonImmutable::yesterday();

    try {
        $summary = $reconcile->run($date);
    } catch (GatewayException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info(__('qris.review.ran', $summary));

    return 0;
})->purpose('Cocokkan pembayaran QRIS dengan laporan settlement gateway');

Schedule::command('meja:reconcile-settlements')->dailyAt('06:10')->withoutOverlapping();
