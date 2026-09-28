<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Reporting\Models\DailySalesSummary;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Refund;
use App\Domain\Sales\TakePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One row per outlet per trading day, so a month of reports is thirty rows
 * rather than every bill in the month.
 *
 * The rows are always rebuilt from scratch from what they summarise, never
 * nudged up or down: whatever changed a day, rebuilding it gives the right
 * answer, and rebuilding twice gives the same one. Anything that changes a
 * day's sales, refunds, or waste calls touch(); a day nobody touched is built
 * the first time a report asks for it.
 */
class DailySummaries
{
    /**
     * Net sales: what was charged, less what was collected for somebody
     * else (tax for the state, service charge for the staff) and the cash
     * rounding. The one definition every report uses.
     */
    public const NET_SALES = 'grand_total - rounding - tax_total - service_charge_total';

    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * Mark a day as changed. It is rebuilt once the change is committed, so a
     * payment that rolls back never leaves its trace in a summary.
     */
    public function touch(string $outletId, ?string $date): void
    {
        if ($date === null) {
            return;
        }

        DB::afterCommit(function () use ($outletId, $date): void {
            try {
                $this->rebuild($outletId, $date);
            } catch (\Throwable $exception) {
                // A summary that failed to rebuild heals on the next touch or
                // rebuild; the sale itself must never fail because of it.
                report($exception);
            }
        });
    }

    public function rebuild(string $outletId, string $date): DailySalesSummary
    {
        $orders = Order::query()
            ->where('outlet_id', $outletId)
            ->where('status', OrderStatus::Paid)
            ->whereNull('merged_into_order_id')
            ->where('business_date', $date);

        $sales = (clone $orders)->toBase()
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(guest_count), 0) as guests')
            ->selectRaw('coalesce(sum(grand_total), 0) as gross')
            ->selectRaw('coalesce(sum('.self::NET_SALES.'), 0) as net')
            ->selectRaw('coalesce(sum(discount_total), 0) as discounts')
            ->selectRaw('coalesce(sum(tax_total), 0) as tax')
            ->selectRaw('coalesce(sum(service_charge_total), 0) as service')
            ->first();

        $items = OrderItem::query()
            ->whereIn('order_id', (clone $orders)->select('orders.id'))
            ->where('status', '!=', OrderItemStatus::Voided->value)
            ->toBase()
            ->selectRaw('coalesce(sum(cost_total), 0) as cost')
            ->selectRaw('coalesce(sum(quantity), 0) as sold')
            ->first();

        $tenders = Payment::query()
            ->whereIn('order_id', (clone $orders)->select('orders.id'))
            ->where('payments.status', TakePayment::STATUS_CAPTURED)
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->toBase()
            ->selectRaw("coalesce(sum(case when payment_methods.type = 'cash' then payments.amount else 0 end), 0) as cash")
            ->selectRaw("coalesce(sum(case when payment_methods.type = 'cash' then 0 else payments.amount end), 0) as non_cash")
            ->selectRaw('coalesce(sum(payments.fee_amount), 0) as fees')
            ->first();

        $refunds = Refund::query()
            ->with('order')
            ->where('outlet_id', $outletId)
            ->where('business_date', $date)
            ->get();

        $waste = StockMovement::query()
            ->where('outlet_id', $outletId)
            ->where('type', StockMovementType::Waste)
            ->where('business_date', $date)
            ->get(['id', 'quantity', 'unit_cost'])
            ->sum(fn (StockMovement $movement): int => $movement->value());

        return DailySalesSummary::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'business_date' => $date],
            [
                'orders' => (int) ($sales->orders ?? 0),
                'guests' => (int) ($sales->guests ?? 0),
                'items_sold' => round((float) ($items->sold ?? 0), 3),
                'gross' => (int) ($sales->gross ?? 0),
                'net_sales' => (int) ($sales->net ?? 0),
                'discounts' => (int) ($sales->discounts ?? 0),
                'tax' => (int) ($sales->tax ?? 0),
                'service_charge' => (int) ($sales->service ?? 0),
                'cost' => (int) ($items->cost ?? 0),
                'cash' => (int) ($tenders->cash ?? 0),
                'non_cash' => (int) ($tenders->non_cash ?? 0),
                'fees' => (int) ($tenders->fees ?? 0),
                'refunds' => $refunds->count(),
                'refunded' => (int) $refunds->sum('amount'),
                'refunded_net' => (int) $refunds->sum(fn (Refund $refund): int => $this->netShare($refund)),
                'waste' => (int) $waste,
                'refreshed_at' => now(),
            ],
        );
    }

    /**
     * The summaries for every day in a range, building any day that has none
     * yet. Days still in the future stay out.
     *
     * @return Collection<string, DailySalesSummary> keyed by Y-m-d, in date order
     */
    public function forRange(Outlet $outlet, DateRange $range): Collection
    {
        $rows = $this->query($outlet, $range)->get()
            ->keyBy(fn (DailySalesSummary $row): string => substr((string) $row->business_date, 0, 10));

        $today = $this->calendar->today($outlet)->toDateString();

        foreach ($range->dates() as $date) {
            if ($date <= $today && ! $rows->has($date)) {
                $rows->put($date, $this->rebuild($outlet->getKey(), $date));
            }
        }

        return $rows->sortKeys();
    }

    /**
     * Rebuild every day in a range, for one outlet or all of them.
     *
     * @return int days rebuilt
     */
    public function rebuildRange(DateRange $range, ?Outlet $outlet = null): int
    {
        $outlets = $outlet === null ? Outlet::withTrashed()->pluck('id') : collect([$outlet->getKey()]);
        $count = 0;

        foreach ($outlets as $outletId) {
            foreach ($range->dates() as $date) {
                $this->rebuild((string) $outletId, $date);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return Builder<DailySalesSummary>
     */
    private function query(Outlet $outlet, DateRange $range): Builder
    {
        return DailySalesSummary::query()
            ->where('outlet_id', $outlet->getKey())
            ->whereBetween('business_date', [$range->fromDate(), $range->toDate()]);
    }

    /**
     * The part of a refund that was net sales. A refund gives back a share of
     * what the guest paid, tax and service included; only its net part comes
     * off the outlet's own takings.
     */
    private function netShare(Refund $refund): int
    {
        $order = $refund->order;

        if (! $order instanceof Order || $order->grand_total <= 0) {
            return $refund->amount;
        }

        $net = $order->grand_total - $order->rounding - $order->tax_total - $order->service_charge_total;

        return (int) round($refund->amount * $net / $order->grand_total);
    }
}
