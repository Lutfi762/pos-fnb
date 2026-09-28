<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Re-reads the trading day of past transactions with an outlet's current
 * clock.
 *
 * Not run when an outlet's day start changes: a trading day that was
 * reported and counted stays as it was. This is for the owner who set the
 * wrong hour on day one and wants the history to follow.
 */
class RedateTransactions
{
    private const DATED = [
        'orders' => 'paid_at',
        'refunds' => 'refunded_at',
        'stock_movements' => 'moved_at',
        'cashier_shifts' => 'opened_at',
    ];

    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * @return int rows whose business date changed
     */
    public function handle(Outlet $outlet): int
    {
        $this->calendar->forget($outlet);
        $changed = 0;
        $zone = (string) config('app.timezone');

        foreach (self::DATED as $table => $column) {
            DB::table($table)
                ->where('outlet_id', $outlet->getKey())
                ->whereNotNull($column)
                ->select(['id', $column, 'business_date'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table, $column, $outlet, $zone, &$changed): void {
                    foreach ($rows as $row) {
                        $date = $this->calendar->dateFor($outlet, CarbonImmutable::parse((string) $row->{$column}, $zone));

                        if (substr((string) $row->business_date, 0, 10) !== $date) {
                            DB::table($table)->where('id', $row->id)->update(['business_date' => $date]);
                            $changed++;
                        }
                    }
                });
        }

        return $changed;
    }
}
