<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\TakePayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the tills did over a stretch of days.
 *
 * Every figure here comes from bills that were actually settled. A bill still
 * open at closing time is tomorrow's revenue, a voided one is nobody's, and a
 * bill merged into another is already counted in the one that swallowed it.
 */
class SalesReport
{
    /**
     * Net sales: what was charged, less the parts collected on behalf of
     * somebody else. Written once here and reused by every query below.
     */
    private const NET_SALES = 'grand_total - rounding - tax_total - service_charge_total';

    public function summary(Outlet $outlet, DateRange $range): SalesSummary
    {
        $row = $this->orders($outlet, $range)
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(grand_total), 0) as gross')
            ->selectRaw('coalesce(sum('.self::NET_SALES.'), 0) as net')
            ->selectRaw('coalesce(sum(discount_total), 0) as discounts')
            ->selectRaw('coalesce(sum(tax_total), 0) as tax')
            ->selectRaw('coalesce(sum(service_charge_total), 0) as service')
            ->selectRaw('coalesce(sum(guest_count), 0) as guests')
            ->first();

        if (! $row instanceof Order) {
            return new SalesSummary;
        }

        return new SalesSummary(
            orders: (int) $row->getAttribute('orders'),
            gross: (int) $row->getAttribute('gross'),
            netSales: (int) $row->getAttribute('net'),
            discounts: (int) $row->getAttribute('discounts'),
            tax: (int) $row->getAttribute('tax'),
            serviceCharge: (int) $row->getAttribute('service'),
            cost: $this->cost($outlet, $range),
            guests: (int) $row->getAttribute('guests'),
        );
    }

    /**
     * One row per day, including the days that sold nothing: a gap in a chart
     * reads as missing data, but a zero reads as a quiet Tuesday.
     *
     * @return Collection<int, \stdClass&object{date: string, orders: int, net: int}>
     */
    public function daily(Outlet $outlet, DateRange $range): Collection
    {
        $rows = $this->orders($outlet, $range)
            ->selectRaw('date(paid_at) as day')
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum('.self::NET_SALES.'), 0) as net')
            ->groupBy('day')
            ->get()
            ->keyBy(fn (Order $row): string => (string) $row->getAttribute('day'));

        return collect($range->dates())->map(fn (string $date): object => (object) [
            'date' => $date,
            'orders' => (int) ($rows->get($date)?->getAttribute('orders') ?? 0),
            'net' => (int) ($rows->get($date)?->getAttribute('net') ?? 0),
        ]);
    }

    /**
     * Best sellers by revenue, grouped on the snapshot name so a renamed dish
     * does not split into two rows halfway through the month.
     *
     * @return Collection<int, \stdClass&object{name: string, quantity: float, net: int, cost: int}>
     */
    public function topProducts(Outlet $outlet, DateRange $range, int $limit = 10): Collection
    {
        return $this->items($outlet, $range)
            ->selectRaw('product_name as name')
            ->selectRaw('coalesce(sum(quantity), 0) as sold')
            ->selectRaw('coalesce(sum(line_total), 0) as net')
            ->selectRaw('coalesce(sum(cost_total), 0) as cost')
            ->groupBy('product_name')
            ->orderByDesc('net')
            ->limit($limit)
            ->get()
            ->map(fn (OrderItem $row): object => (object) [
                'name' => (string) $row->getAttribute('name'),
                'quantity' => (float) $row->getAttribute('sold'),
                'net' => (int) $row->getAttribute('net'),
                'cost' => (int) $row->getAttribute('cost'),
            ]);
    }

    /**
     * How the money came in. Fees are shown because a QRIS sale is worth
     * slightly less than the same sale in cash.
     *
     * @return Collection<int, \stdClass&object{name: string, count: int, total: int, fee: int}>
     */
    public function paymentMethods(Outlet $outlet, DateRange $range): Collection
    {
        return Payment::query()
            ->whereIn('order_id', $this->orders($outlet, $range)->select('orders.id'))
            ->where('status', TakePayment::STATUS_CAPTURED)
            ->selectRaw('method_name as name')
            ->selectRaw('count(*) as tenders')
            ->selectRaw('coalesce(sum(amount), 0) as total')
            ->selectRaw('coalesce(sum(fee_amount), 0) as fee')
            ->groupBy('method_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn (Payment $row): object => (object) [
                'name' => (string) $row->getAttribute('name'),
                'count' => (int) $row->getAttribute('tenders'),
                'total' => (int) $row->getAttribute('total'),
                'fee' => (int) $row->getAttribute('fee'),
            ]);
    }

    /**
     * Shifts closed in this range, newest first, for the drawer variance.
     *
     * @return Collection<int, CashierShift>
     */
    public function shifts(Outlet $outlet, DateRange $range): Collection
    {
        return CashierShift::query()
            ->with('cashier')
            ->where('outlet_id', $outlet->getKey())
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$range->from, $range->to])
            ->orderByDesc('closed_at')
            ->limit(50)
            ->get();
    }

    /**
     * Cost of what was sold, from the snapshot written at ring-up time.
     */
    private function cost(Outlet $outlet, DateRange $range): int
    {
        return (int) $this->items($outlet, $range)->sum('cost_total');
    }

    /**
     * Settled bills in the range.
     *
     * Paid time, not created time: a bill opened at 23:50 and settled at 00:10
     * belongs to the day it was paid for, which is how the drawer counts it.
     *
     * @return Builder<Order>
     */
    private function orders(Outlet $outlet, DateRange $range): Builder
    {
        return Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('status', OrderStatus::Paid)
            ->whereNull('merged_into_order_id')
            ->whereBetween('paid_at', [$range->from, $range->to]);
    }

    /**
     * Lines belonging to those bills, minus the ones that were sent back.
     *
     * @return Builder<OrderItem>
     */
    private function items(Outlet $outlet, DateRange $range): Builder
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->orders($outlet, $range)->select('orders.id'))
            ->where('status', '!=', OrderItemStatus::Voided->value);
    }
}
