<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * The bills list: which bills a set of filters picks out.
 *
 * A settled bill is found by the trading day it was paid in. A bill that
 * was never paid has no trading day, so it is found by when it was opened,
 * inside the same trading-day window.
 */
class OrderSearch
{
    public const STATUSES = ['all', 'paid', 'open', 'voided'];

    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * @param  array{status?: string, cashier?: int|string|null, method?: string|null, search?: string|null}  $filters
     * @return Builder<Order>
     */
    public function query(Outlet $outlet, DateRange $range, array $filters = []): Builder
    {
        $status = in_array($filters['status'] ?? 'all', self::STATUSES, true) ? ($filters['status'] ?? 'all') : 'all';
        [$from] = $this->calendar->bounds($outlet, $range->fromDate());
        [, $to] = $this->calendar->bounds($outlet, $range->toDate());

        $search = trim((string) ($filters['search'] ?? ''));
        $method = trim((string) ($filters['method'] ?? ''));
        $cashier = $filters['cashier'] ?? null;

        return Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->whereNull('merged_into_order_id')
            ->where(fn (Builder $query) => $query
                ->whereBetween('business_date', [$range->fromDate(), $range->toDate()])
                ->orWhere(fn (Builder $open) => $open->whereNull('business_date')->whereBetween('placed_at', [$from, $to])))
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', OrderStatus::from($status)))
            ->when($cashier !== null && $cashier !== '', fn (Builder $query) => $query->where('user_id', $cashier))
            ->when($method !== '', fn (Builder $query) => $query->whereHas('payments', fn (Builder $payments) => $payments->where('method_name', $method)))
            ->when($search !== '', fn (Builder $query) => $query->where('number', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%'))
            ->with(['cashier', 'diningTable', 'payments'])
            ->withCount('items')
            ->orderByDesc('placed_at');
    }
}
