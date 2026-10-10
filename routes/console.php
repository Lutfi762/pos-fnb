<?php

use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DailySummaries;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\RedateTransactions;
use App\Domain\Reporting\ReportExports;
use App\Domain\Sales\Gateway\GatewayException;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\ReconcileSettlement;
use App\Support\Backup\DatabaseBackup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Number;

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

// Rebuild the daily summaries from the bills behind them. Safe to run any
// number of times; the result only depends on the transactions.
Artisan::command('meja:rebuild-summaries
    {--from= : Tanggal awal (YYYY-MM-DD), default transaksi pertama}
    {--to= : Tanggal akhir (YYYY-MM-DD), default hari ini}
    {--outlet= : Kode outlet, default semua}
    {--redate : Hitung ulang tanggal operasional transaksi lama dengan jam outlet sekarang}', function (DailySummaries $summaries, RedateTransactions $redater) {
    $code = $this->option('outlet');
    $outlet = is_string($code) && $code !== '' ? Outlet::query()->where('code', $code)->first() : null;

    if (is_string($code) && $code !== '' && $outlet === null) {
        $this->error(__('reports.rebuild.unknown_outlet', ['code' => $code]));

        return 1;
    }

    // Read before redating: days that lose transactions need rebuilding too.
    $first = Order::query()->when($outlet, fn ($query) => $query->where('outlet_id', $outlet->getKey()))->min('business_date');

    if ($this->option('redate')) {
        foreach ($outlet !== null ? [$outlet] : Outlet::query()->get()->all() as $each) {
            $this->line(__('reports.rebuild.redated', ['outlet' => $each->name, 'count' => $redater->handle($each)]));
        }
    }

    $from = $this->option('from');
    $to = $this->option('to');

    $range = DateRange::of(
        is_string($from) && $from !== '' ? $from : ($first !== null ? (string) $first : now()),
        is_string($to) && $to !== '' ? $to : now(),
    );

    $days = $summaries->rebuildRange($range, $outlet);

    $this->info(__('reports.rebuild.done', ['days' => $days, 'range' => $range->label()]));

    return 0;
})->purpose('Bangun ulang ringkasan penjualan harian');

// Exported files are kept for a day, then removed.
Artisan::command('meja:prune-exports', function (ReportExports $exports) {
    $this->info(__('reports.pruned', ['count' => $exports->prune()]));

    return 0;
})->purpose('Hapus file ekspor laporan yang sudah kedaluwarsa');

// A compressed copy of the database, oldest copies rotated out.
Artisan::command('meja:backup
    {--keep= : Jumlah cadangan yang disimpan, default BACKUP_KEEP}
    {--connection= : Koneksi database, default DB_CONNECTION}', function (DatabaseBackup $backup) {
    $connection = $this->option('connection');

    try {
        $path = $backup->run(is_string($connection) && $connection !== '' ? $connection : null);
    } catch (RuntimeException $exception) {
        $this->error(__('backup.failed', ['error' => $exception->getMessage()]));

        return 1;
    }

    $this->info(__('backup.done', ['path' => $path, 'size' => Number::fileSize((int) filesize($path))]));

    $keep = $this->option('keep');
    $keep = is_numeric($keep) ? (int) $keep : (int) config('backup.keep');

    $this->line(__('backup.pruned', ['count' => $backup->prune($keep), 'kept' => min($keep, count($backup->list()))]));

    return 0;
})->purpose('Cadangkan database ke folder BACKUP_PATH');

Schedule::command('meja:prune-exports')->hourly();

// After the default trading day has closed (04.00) and before opening.
Schedule::command('meja:backup')->dailyAt('04:30')->withoutOverlapping();

Schedule::command('meja:reconcile-settlements')->dailyAt('06:10')->withoutOverlapping();
