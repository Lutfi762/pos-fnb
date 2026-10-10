<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Reporting\Models\DailySalesSummary;
use App\Domain\Reporting\Tables\Column;
use App\Domain\Reporting\Tables\ReportTable;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\CashMovement;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Every report the back office can read, print, or download, built as a
 * ReportTable.
 *
 * Figures come from the same places as the dashboard and the headline
 * tiles (the daily summaries, and the bills by business date), so a total
 * in an Excel file matches the number on the screen it was downloaded from.
 */
class ReportCatalog
{
    public const TYPES = ['daily', 'products', 'gross_profit', 'cash_flow', 'orders'];

    /** Reports that show cost, margin, or cash: management figures. */
    public const MANAGEMENT = ['gross_profit', 'cash_flow'];

    public function __construct(
        private readonly DailySummaries $summaries,
        private readonly OrderSearch $search,
        private readonly BusinessCalendar $calendar,
    ) {}

    public function title(string $type): string
    {
        return __('reports.types.'.$type);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function build(string $type, Outlet $outlet, DateRange $range, array $filters = [], bool $withCost = true): ReportTable
    {
        return match ($type) {
            'daily' => $this->daily($outlet, $range, $withCost),
            'products' => $this->products($outlet, $range, $withCost),
            'gross_profit' => $this->grossProfit($outlet, $range),
            'cash_flow' => $this->cashFlow($outlet, $range),
            'orders' => $this->orders($outlet, $range, $filters),
            default => throw new InvalidArgumentException("Unknown report [{$type}]."),
        };
    }

    /**
     * Rows a report will have, without building it: big ones go to the queue.
     *
     * @param  array<string, mixed>  $filters
     */
    public function count(string $type, Outlet $outlet, DateRange $range, array $filters = []): int
    {
        return match ($type) {
            'orders' => $this->search->query($outlet, $range, $filters)->count(),
            'products' => $this->items($outlet, $range)->distinct()->count('product_name'),
            default => $range->days(),
        };
    }

    public function daily(Outlet $outlet, DateRange $range, bool $withCost = true): ReportTable
    {
        $days = $this->days($outlet, $range);

        $columns = [
            new Column(__('reports.date'), Column::DATE),
            new Column(__('reports.orders'), Column::INTEGER),
            new Column(__('reports.guests'), Column::INTEGER),
            new Column(__('reports.gross'), Column::MONEY),
            new Column(__('reports.discounts'), Column::MONEY),
            new Column(__('reports.service_charge'), Column::MONEY),
            new Column(__('reports.tax'), Column::MONEY),
            new Column(__('reports.net_sales'), Column::MONEY),
            new Column(__('reports.refunded'), Column::MONEY),
        ];

        if ($withCost) {
            array_push(
                $columns,
                new Column(__('reports.cost'), Column::MONEY),
                new Column(__('reports.gross_profit'), Column::MONEY),
                new Column(__('reports.waste'), Column::MONEY),
            );
        }

        $rows = $days->map(fn (DailySalesSummary $day, string $date): array => [
            CarbonImmutable::parse($date),
            $day->orders,
            $day->guests,
            $day->gross,
            $day->discounts,
            $day->service_charge,
            $day->tax,
            $day->net_sales,
            $day->refunded,
            ...($withCost ? [$day->cost, $day->net_sales - $day->cost, $day->waste] : []),
        ])->values()->all();

        return new ReportTable(
            title: $this->title('daily'),
            columns: $columns,
            rows: $rows,
            totals: $this->totals($rows, $columns),
            meta: $this->meta($outlet, $range),
        );
    }

    public function products(Outlet $outlet, DateRange $range, bool $withCost = true): ReportTable
    {
        $lines = $this->items($outlet, $range)
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->toBase()
            ->selectRaw('categories.name as category, order_items.product_name as product')
            ->selectRaw('coalesce(sum(order_items.quantity), 0) as sold')
            ->selectRaw('coalesce(sum(order_items.line_total), 0) as net')
            ->selectRaw('coalesce(sum(order_items.cost_total), 0) as cost')
            ->groupBy('categories.name', 'order_items.product_name')
            ->orderByDesc('net')
            ->get();

        $columns = [
            new Column(__('reports.category'), Column::TEXT),
            new Column(__('reports.product'), Column::TEXT),
            new Column(__('reports.sold'), Column::DECIMAL),
            new Column(__('reports.line_sales'), Column::MONEY),
        ];

        if ($withCost) {
            array_push(
                $columns,
                new Column(__('reports.cost'), Column::MONEY),
                new Column(__('reports.gross_profit'), Column::MONEY),
                new Column(__('reports.margin'), Column::PERCENT),
            );
        }

        $rows = $lines->map(function (object $line) use ($withCost): array {
            $net = (int) $line->net;
            $cost = (int) $line->cost;

            return [
                (string) ($line->category ?? __('reports.uncategorised')),
                (string) $line->product,
                round((float) $line->sold, 3),
                $net,
                ...($withCost ? [$cost, $net - $cost, $this->margin($net - $cost, $net)] : []),
            ];
        })->values()->all();

        $totals = $this->totals($rows, $columns);

        if ($withCost && $totals !== null) {
            $totals[6] = $this->margin((int) $totals[5], (int) $totals[3]);
        }

        return new ReportTable(
            title: $this->title('products'),
            columns: $columns,
            rows: $rows,
            totals: $totals,
            meta: $this->meta($outlet, $range) + [__('reports.note') => __('reports.line_sales_hint')],
        );
    }

    public function grossProfit(Outlet $outlet, DateRange $range): ReportTable
    {
        $columns = [
            new Column(__('reports.date'), Column::DATE),
            new Column(__('reports.net_sales'), Column::MONEY),
            new Column(__('reports.refunded_net'), Column::MONEY),
            new Column(__('reports.cost'), Column::MONEY),
            new Column(__('reports.waste'), Column::MONEY),
            new Column(__('reports.gross_profit'), Column::MONEY),
            new Column(__('reports.margin'), Column::PERCENT),
        ];

        $rows = $this->days($outlet, $range)->map(function (DailySalesSummary $day, string $date): array {
            $kept = $day->net_sales - $day->refunded_net;
            $profit = $kept - $day->cost - $day->waste;

            return [CarbonImmutable::parse($date), $day->net_sales, $day->refunded_net, $day->cost, $day->waste, $profit, $this->margin($profit, $kept)];
        })->values()->all();

        $totals = $this->totals($rows, $columns);

        if ($totals !== null) {
            $totals[6] = $this->margin((int) $totals[5], (int) $totals[1] - (int) $totals[2]);
        }

        return new ReportTable(
            title: $this->title('gross_profit'),
            columns: $columns,
            rows: $rows,
            totals: $totals,
            meta: $this->meta($outlet, $range) + [__('reports.note') => __('reports.gross_profit_hint')],
        );
    }

    /**
     * Money in and out per trading day. Sales are counted when paid, refunds
     * when given back, drawer movements when made, and stock purchases when
     * the goods arrived (what the supplier will be owed).
     */
    public function cashFlow(Outlet $outlet, DateRange $range): ReportTable
    {
        $days = $this->days($outlet, $range);
        $dates = $days->keys()->all();

        $refunds = Refund::query()
            ->where('refunds.outlet_id', $outlet->getKey())
            ->whereBetween('refunds.business_date', [$range->fromDate(), $range->toDate()])
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'refunds.payment_method_id')
            ->toBase()
            ->selectRaw('refunds.business_date as day')
            ->selectRaw("coalesce(sum(case when payment_methods.type = 'cash' then refunds.amount else 0 end), 0) as cash")
            ->selectRaw("coalesce(sum(case when payment_methods.type = 'cash' then 0 else refunds.amount end), 0) as other")
            ->groupBy('refunds.business_date')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->day, 0, 10));

        $drawer = $this->drawerMoves($outlet, $range);

        $purchases = StockMovement::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('type', StockMovementType::Purchase)
            ->whereBetween('business_date', [$range->fromDate(), $range->toDate()])
            ->get(['id', 'quantity', 'unit_cost', 'business_date'])
            ->groupBy(fn (StockMovement $movement): string => substr((string) $movement->getAttribute('business_date'), 0, 10))
            ->map(fn (Collection $movements): int => (int) $movements->sum(fn (StockMovement $movement): int => $movement->value()));

        $columns = [
            new Column(__('reports.date'), Column::DATE),
            new Column(__('reports.cash_sales'), Column::MONEY),
            new Column(__('reports.non_cash_sales'), Column::MONEY),
            new Column(__('reports.gateway_fees'), Column::MONEY),
            new Column(__('reports.cash_refunds'), Column::MONEY),
            new Column(__('reports.other_refunds'), Column::MONEY),
            new Column(__('reports.cash_in'), Column::MONEY),
            new Column(__('reports.cash_out'), Column::MONEY),
            new Column(__('reports.purchases'), Column::MONEY),
            new Column(__('reports.net_cash_flow'), Column::MONEY),
        ];

        $rows = [];

        foreach ($dates as $date) {
            $day = $days->get($date);
            $refund = $refunds->get($date);
            $cashRefunds = (int) ($refund->cash ?? 0);
            $otherRefunds = (int) ($refund->other ?? 0);
            $in = $drawer[$date]['in'] ?? 0;
            $out = $drawer[$date]['out'] ?? 0;
            $bought = (int) $purchases->get($date, 0);

            $rows[] = [
                CarbonImmutable::parse($date),
                $day->cash,
                $day->non_cash,
                $day->fees,
                $cashRefunds,
                $otherRefunds,
                $in,
                $out,
                $bought,
                $day->cash + $day->non_cash - $day->fees - $cashRefunds - $otherRefunds + $in - $out - $bought,
            ];
        }

        return new ReportTable(
            title: $this->title('cash_flow'),
            columns: $columns,
            rows: $rows,
            totals: $this->totals($rows, $columns),
            meta: $this->meta($outlet, $range) + [__('reports.note') => __('reports.cash_flow_hint')],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function orders(Outlet $outlet, DateRange $range, array $filters = []): ReportTable
    {
        $columns = [
            new Column(__('orders.number'), Column::TEXT),
            new Column(__('orders.placed_at'), Column::DATETIME),
            new Column(__('orders.paid_at'), Column::DATETIME),
            new Column(__('orders.status_label'), Column::TEXT),
            new Column(__('orders.type_label'), Column::TEXT),
            new Column(__('orders.cashier'), Column::TEXT),
            new Column(__('orders.items'), Column::INTEGER),
            new Column(__('reports.discounts'), Column::MONEY),
            new Column(__('reports.service_charge'), Column::MONEY),
            new Column(__('reports.tax'), Column::MONEY),
            new Column(__('reports.total'), Column::MONEY),
            new Column(__('orders.methods'), Column::TEXT),
            new Column(__('reports.refunded'), Column::MONEY),
        ];

        $rows = [];

        foreach ($this->search->query($outlet, $range, $filters)->lazy(500) as $order) {
            $rows[] = $this->orderRow($order);
        }

        return new ReportTable(
            title: $this->title('orders'),
            columns: $columns,
            rows: $rows,
            totals: $this->totals($rows, $columns),
            meta: $this->meta($outlet, $range),
        );
    }

    /**
     * @return list<int|float|string|CarbonImmutable|null>
     */
    public function orderRow(Order $order): array
    {
        return [
            $order->number,
            $order->getAttribute('placed_at'),
            $order->paid_at,
            $order->status->label(),
            $order->type->label().($order->diningTable ? ' '.$order->diningTable->getAttribute('name') : ''),
            $order->cashier?->name,
            (int) $order->getAttribute('items_count'),
            (int) $order->getAttribute('discount_total'),
            (int) $order->getAttribute('service_charge_total'),
            (int) $order->getAttribute('tax_total'),
            (int) $order->getAttribute('grand_total'),
            $order->payments->pluck('method_name')->unique()->implode(', '),
            (int) $order->getAttribute('refunded_total'),
        ];
    }

    /**
     * Summaries for each trading day in the range that has started.
     *
     * @return Collection<string, DailySalesSummary>
     */
    private function days(Outlet $outlet, DateRange $range): Collection
    {
        $today = $this->calendar->today($outlet)->toDateString();

        return $this->summaries->forRange($outlet, $range)
            ->filter(fn (DailySalesSummary $day, string $date): bool => $date <= $today);
    }

    /**
     * Cash put into or taken out of drawers, per trading day.
     *
     * @return array<string, array{in: int, out: int}>
     */
    private function drawerMoves(Outlet $outlet, DateRange $range): array
    {
        [$from] = $this->calendar->bounds($outlet, $range->fromDate());
        [, $to] = $this->calendar->bounds($outlet, $range->toDate());

        $moves = [];

        foreach (CashMovement::query()->where('outlet_id', $outlet->getKey())->whereBetween('moved_at', [$from, $to])->get(['id', 'direction', 'amount', 'moved_at']) as $move) {
            $date = $this->calendar->dateFor($outlet, $move->getAttribute('moved_at'));
            $key = $move->getAttribute('direction') === CashMovement::IN ? 'in' : 'out';
            $moves[$date] ??= ['in' => 0, 'out' => 0];
            $moves[$date][$key] += (int) $move->getAttribute('amount');
        }

        return $moves;
    }

    /**
     * @return Builder<OrderItem>
     */
    private function items(Outlet $outlet, DateRange $range): Builder
    {
        return OrderItem::query()
            ->whereIn('order_items.order_id', Order::query()
                ->select('orders.id')
                ->where('outlet_id', $outlet->getKey())
                ->where('status', OrderStatus::Paid)
                ->whereNull('merged_into_order_id')
                ->whereBetween('business_date', [$range->fromDate(), $range->toDate()]))
            ->where('order_items.status', '!=', OrderItemStatus::Voided->value);
    }

    /**
     * Sum of every numeric column, "Total" in the first.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  list<Column>  $columns
     * @return array<int, int|float|string|null>|null
     */
    private function totals(array $rows, array $columns): ?array
    {
        if ($rows === []) {
            return null;
        }

        $totals = [];

        foreach ($columns as $index => $column) {
            $totals[] = match (true) {
                $index === 0 => (string) __('reports.total'),
                in_array($column->type, [Column::MONEY, Column::INTEGER], true) => (int) array_sum(array_map(fn (array $row): int => (int) ($row[$index] ?? 0), $rows)),
                $column->type === Column::DECIMAL => round(array_sum(array_map(fn (array $row): float => (float) ($row[$index] ?? 0), $rows)), 3),
                default => null,
            };
        }

        return $totals;
    }

    private function margin(int $profit, int $sales): float
    {
        return $sales === 0 ? 0.0 : round($profit * 100 / $sales, 1);
    }

    /**
     * @return array<string, string>
     */
    private function meta(Outlet $outlet, DateRange $range): array
    {
        return [
            __('reports.outlet') => $outlet->name,
            __('reports.period') => $range->label(),
            __('reports.generated_at') => now()->translatedFormat('d M Y H:i'),
        ];
    }
}
