<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outlet's trading day, added up. Rebuilt from the bills, refunds, and
 * stock movements behind it whenever one of them changes; never edited.
 *
 * The business date is kept as the plain Y-m-d string the database holds,
 * so it compares and groups the same on MySQL and SQLite.
 *
 * @property string $business_date
 */
class DailySalesSummary extends DomainModel
{
    protected function casts(): array
    {
        return [
            'orders' => 'integer',
            'guests' => 'integer',
            'items_sold' => 'decimal:3',
            'gross' => 'integer',
            'net_sales' => 'integer',
            'discounts' => 'integer',
            'tax' => 'integer',
            'service_charge' => 'integer',
            'cost' => 'integer',
            'cash' => 'integer',
            'non_cash' => 'integer',
            'fees' => 'integer',
            'refunds' => 'integer',
            'refunded' => 'integer',
            'refunded_net' => 'integer',
            'waste' => 'integer',
            'refreshed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
