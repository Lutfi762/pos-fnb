<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Sales\Enums\ChargeStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\GatewaySettlement;
use App\Domain\Sales\Models\GatewayWebhook;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers on the landing page, each one answering a question someone
 * asks while the shift is still running: are we up on last week, when does
 * the rush come, what is about to run out, and is anything broken.
 */
class DashboardMetrics
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly DailySummaries $summaries,
        private readonly SalesReport $report,
    ) {}

    public function today(Outlet $outlet): CarbonImmutable
    {
        return $this->calendar->today($outlet);
    }

    /**
     * Today so far, and the same weekday last week up to the same minute.
     * Comparing a half-finished Monday with a whole one would say every
     * morning is a disaster.
     *
     * @return array{current: SalesSummary, previous: SalesSummary}
     */
    public function pace(Outlet $outlet): array
    {
        $today = $this->today($outlet);
        $current = $this->report->summary($outlet, DateRange::of($today, $today));

        $lastWeek = $today->subWeek()->toDateString();
        $cutoff = CarbonImmutable::now()->subWeek();

        $row = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('status', OrderStatus::Paid)
            ->whereNull('merged_into_order_id')
            ->where('business_date', $lastWeek)
            ->where('paid_at', '<=', $cutoff)
            ->toBase()
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(grand_total), 0) as gross')
            ->selectRaw('coalesce(sum('.DailySummaries::NET_SALES.'), 0) as net')
            ->selectRaw('coalesce(sum(guest_count), 0) as guests')
            ->first();

        return [
            'current' => $current,
            'previous' => new SalesSummary(
                orders: (int) ($row->orders ?? 0),
                gross: (int) ($row->gross ?? 0),
                netSales: (int) ($row->net ?? 0),
                guests: (int) ($row->guests ?? 0),
            ),
        ];
    }

    /**
     * The last seven trading days against the seven before them, day by day.
     *
     * @return list<array{date: string, label: string, net: int, orders: int, previous: int, current: bool}>
     */
    public function week(Outlet $outlet): array
    {
        $today = $this->today($outlet);
        $range = DateRange::of($today->subDays(6), $today);

        $days = $this->summaries->forRange($outlet, $range);
        $before = $this->summaries->forRange($outlet, DateRange::of($today->subDays(13), $today->subDays(7)));

        $series = [];

        foreach ($range->dates() as $date) {
            $day = CarbonImmutable::parse($date);
            $previous = $before->get($day->subWeek()->toDateString());

            $series[] = [
                'date' => $date,
                'label' => $day->translatedFormat('D d/m'),
                'net' => (int) $days->get($date)?->getAttribute('net_sales'),
                'orders' => (int) $days->get($date)?->getAttribute('orders'),
                'previous' => (int) $previous?->getAttribute('net_sales'),
                'current' => $date === $today->toDateString(),
            ];
        }

        return $series;
    }

    /**
     * Bills per hour of the day and day of the week over the last four
     * weeks, in the outlet's own clock. Rows are trading days, so 01:00 on a
     * Saturday night sits in Saturday's row, where the staff would put it.
     *
     * @return array{hours: list<int>, rows: list<array{weekday: int, label: string, cells: list<array{hour: int, orders: int, level: int}>}>, peak: array{weekday: string, hour: int, orders: int}|null}
     */
    public function heatmap(Outlet $outlet, int $weeks = 4): array
    {
        $today = $this->today($outlet);
        ['zone' => $zone, 'hour' => $start] = $this->calendar->clockOf($outlet);

        $orders = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('status', OrderStatus::Paid)
            ->whereNull('merged_into_order_id')
            ->whereBetween('business_date', [$today->subWeeks($weeks)->addDay()->toDateString(), $today->toDateString()])
            ->toBase()
            ->get(['paid_at', 'business_date']);

        // Monday first, the way an Indonesian roster is written.
        $counts = array_fill(1, 7, array_fill(0, 24, 0));

        foreach ($orders as $order) {
            $weekday = CarbonImmutable::parse((string) $order->business_date)->dayOfWeekIso;
            $hour = CarbonImmutable::parse((string) $order->paid_at, (string) config('app.timezone'))->setTimezone($zone)->hour;
            $counts[$weekday][$hour]++;
        }

        $max = max(1, ...array_map(fn (array $row): int => max($row), $counts));

        // Hours in trading order: from the day's start round to just before it.
        $hours = array_map(fn (int $offset): int => ($start + $offset) % 24, range(0, 23));

        // Drop the empty hours at both ends, so a cafe open 07-22 is not
        // drawn as a mostly blank strip.
        $busy = array_values(array_filter($hours, fn (int $hour): bool => array_sum(array_column($counts, $hour)) > 0));
        $shown = $busy === [] ? array_slice($hours, 4, 14) : array_slice(
            $hours,
            (int) array_search($busy[0], $hours, true),
            (int) array_search(end($busy), $hours, true) - (int) array_search($busy[0], $hours, true) + 1,
        );

        $rows = [];
        $peak = null;

        foreach ($counts as $weekday => $row) {
            $label = CarbonImmutable::now()->startOfWeek()->addDays($weekday - 1)->translatedFormat('D');
            $cells = [];

            foreach ($shown as $hour) {
                $orders = $row[$hour];
                $cells[] = ['hour' => $hour, 'orders' => $orders, 'level' => $orders === 0 ? 0 : (int) ceil($orders * 4 / $max)];

                if ($orders > ($peak['orders'] ?? 0)) {
                    $peak = ['weekday' => $label, 'hour' => $hour, 'orders' => $orders];
                }
            }

            $rows[] = ['weekday' => $weekday, 'label' => $label, 'cells' => $cells];
        }

        return ['hours' => $shown, 'rows' => $rows, 'peak' => $peak];
    }

    /**
     * Ingredients at or under their reorder point, emptiest first.
     *
     * @return Collection<int, StockLevel>
     */
    public function criticalStock(Outlet $outlet, int $limit = 6): Collection
    {
        return StockLevel::query()
            ->with('item.unit')
            ->where('outlet_id', $outlet->getKey())
            ->where(fn ($query) => $query->where('quantity', '<', 0)
                ->orWhere(fn ($inner) => $inner->where('reorder_point', '>', 0)->whereColumn('quantity', '<=', 'reorder_point')))
            ->orderByRaw('case when reorder_point > 0 then quantity / reorder_point else quantity end')
            ->limit($limit)
            ->get();
    }

    /**
     * Today's waste by reason, in rupiah.
     *
     * @return Collection<string, int>
     */
    public function wasteByReason(Outlet $outlet): Collection
    {
        return StockMovement::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('type', StockMovementType::Waste)
            ->where('business_date', $this->today($outlet)->toDateString())
            ->get(['id', 'quantity', 'unit_cost', 'reason'])
            ->groupBy(fn (StockMovement $movement): string => (string) ($movement->reason ?? 'other'))
            ->map(fn (Collection $movements): int => (int) $movements->sum(fn (StockMovement $movement): int => $movement->value()))
            ->sortDesc();
    }

    /**
     * Bills settled today per cashier, for the manager's end-of-day look.
     *
     * @return Collection<int, \stdClass&object{name: string, orders: int, net: int}>
     */
    public function cashiers(Outlet $outlet): Collection
    {
        $rows = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('status', OrderStatus::Paid)
            ->whereNull('merged_into_order_id')
            ->where('business_date', $this->today($outlet)->toDateString())
            ->toBase()
            ->selectRaw('user_id, count(*) as orders, coalesce(sum('.DailySummaries::NET_SALES.'), 0) as net')
            ->groupBy('user_id')
            ->orderByDesc('net')
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        return $rows->map(fn (object $row): object => (object) [
            'name' => (string) ($names->get($row->user_id) ?? '-'),
            'orders' => (int) $row->orders,
            'net' => (int) $row->net,
        ])->values();
    }

    /**
     * What needs someone's attention before it becomes a problem.
     *
     * @return array{sync_failed: int, devices_stale: int, static_qris: int, qris_unapplied: int, webhooks_failed: int, last_settlement: string|null, negative_stock: int}
     */
    public function status(Outlet $outlet): array
    {
        $outletId = $outlet->getKey();

        return [
            'sync_failed' => SyncOperation::query()->where('outlet_id', $outletId)
                ->whereIn('status', [SyncStatus::Failed->value, SyncStatus::Conflict->value])->count(),

            // A till that has not been heard from in a day may be holding sales.
            'devices_stale' => Device::query()->where('outlet_id', $outletId)
                ->where('last_seen_at', '<', now()->subDay())->where('last_seen_at', '>', now()->subDays(14))->count(),

            'static_qris' => Payment::query()->where('verification', Payment::UNVERIFIED)
                ->whereHas('order', fn ($query) => $query->where('outlet_id', $outletId))->count(),

            'qris_unapplied' => PaymentCharge::query()->where('outlet_id', $outletId)
                ->where('status', ChargeStatus::Unapplied)->whereNull('resolved_at')->count(),

            'webhooks_failed' => GatewayWebhook::query()->where('status', GatewayWebhook::FAILED)
                ->where('created_at', '>', now()->subWeek())->count(),

            'last_settlement' => ($last = GatewaySettlement::query()->max('transaction_date')) === null ? null : substr((string) $last, 0, 10),

            'negative_stock' => StockLevel::query()->where('outlet_id', $outletId)->where('quantity', '<', 0)->count(),
        ];
    }

    /**
     * Every outlet an owner runs, today against the same weekday last week
     * up to the same minute, like the headline figure.
     *
     * @return list<array{outlet: Outlet, net: int, orders: int, previous: int, changeBps: int}>
     */
    public function outlets(User $owner): array
    {
        $rows = [];

        foreach ($owner->accessibleOutlets() as $outlet) {
            ['current' => $current, 'previous' => $previous] = $this->pace($outlet);

            $rows[] = [
                'outlet' => $outlet,
                'net' => $current->netSales,
                'orders' => $current->orders,
                'previous' => $previous->netSales,
                'changeBps' => $current->changeBps($previous),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['net'] <=> $a['net']);

        return $rows;
    }
}
