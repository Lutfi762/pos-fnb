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
 * What the tills did over a stretch of trading days.
 *
 * Every figure here comes from bills that were actually settled. A bill still
 * open at closing time is tomorrow's revenue, a voided one is nobody's, and a
 * bill merged into another is already counted in the one that swallowed it.
 *
 * Headline figures and the day-by-day series read the daily summaries;
 * breakdowns that need individual lines (products, payment methods) read the
 * bills, filtered by the same business date.
 */
class SalesReport
{
    public function __construct(private readonly DailySummaries $summaries) {}

    public function summary(Outlet $outlet, DateRange $range): SalesSummary
    {
        $days = $this->summaries->forRange($outlet, $range);

        return new SalesSummary(
            orders: (int) $days->sum('orders'),
            gross: (int) $days->sum('gross'),
            netSales: (int) $days->sum('net_sales'),
            discounts: (int) $days->sum('discounts'),
            tax: (int) $days->sum('tax'),
            serviceCharge: (int) $days->sum('service_charge'),
            cost: (int) $days->sum('cost'),
            guests: (int) $days->sum('guests'),
            refunds: (int) $days->sum('refunds'),
            refunded: (int) $days->sum('refunded'),
            refundedNet: (int) $days->sum('refunded_net'),
            waste: (int) $days->sum('waste'),
        );
    }

    /**
     * One row per day, including the days that sold nothing: a gap in a chart
     * reads as missing data, but a zero reads as a quiet Tuesday.
     *
     * @return Collection<int, \stdClass&object{date: string, orders: int, net: int, refunded: int, waste: int}>
     */
    public function daily(Outlet $outlet, DateRange $range): Collection
    {
        $days = $this->summaries->forRange($outlet, $range);

        return collect($range->dates())->map(fn (string $date): object => (object) [
            'date' => $date,
            'orders' => (int) $days->get($date)?->getAttribute('orders'),
            'net' => (int) $days->get($date)?->getAttribute('net_sales'),
            'refunded' => (int) $days->get($date)?->getAttribute('refunded'),
            'waste' => (int) $days->get($date)?->getAttribute('waste'),
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
     * Shifts opened on these trading days and since closed, newest first,
     * for the drawer variance.
     *
     * @return Collection<int, CashierShift>
     */
    public function shifts(Outlet $outlet, DateRange $range): Collection
    {
        return CashierShift::query()
            ->with('cashier')
            ->where('outlet_id', $outlet->getKey())
            ->whereNotNull('closed_at')
            ->whereBetween('business_date', [$range->fromDate(), $range->toDate()])
            ->orderByDesc('closed_at')
            ->limit(50)
            ->get();
    }

    /**
     * Settled bills on these trading days.
     *
     * The business date is fixed when the bill is paid: a bill opened at
     * 23:50 and settled at 00:10 belongs to the night it was served in, as
     * long as the outlet's day has not rolled over yet.
     *
     * @return Builder<Order>
     */
    private function orders(Outlet $outlet, DateRange $range): Builder
    {
        return Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('status', OrderStatus::Paid)
            ->whereNull('merged_into_order_id')
            ->whereBetween('business_date', [$range->fromDate(), $range->toDate()]);
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
