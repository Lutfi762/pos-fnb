<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\SalesReport;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->report = app(SalesReport::class);
    $this->today = DateRange::of(now(), now());
});

/**
 * A settled bill with one line, priced so the sums are easy to read.
 */
function settled(Outlet $outlet, int $gross, ?CarbonImmutable $paidAt = null, int $cost = 0): Order
{
    $order = Order::factory()->for($outlet)->create([
        'status' => OrderStatus::Paid,
        'grand_total' => $gross,
        'tax_total' => 0,
        'service_charge_total' => 0,
        'rounding' => 0,
        'paid_at' => $paidAt ?? now(),
        'paid_total' => $gross,
    ]);

    OrderItem::factory()->for($order)->create([
        'line_total' => $gross,
        'cost_total' => $cost,
    ]);

    return $order;
}

it('sums only what was settled in the range', function (): void {
    settled($this->outlet, 50_000);
    settled($this->outlet, 30_000);

    // Yesterday's takings belong to yesterday's report.
    settled($this->outlet, 99_000, CarbonImmutable::now()->subDay());

    $summary = $this->report->summary($this->outlet, $this->today);

    expect($summary->orders)->toBe(2)
        ->and($summary->netSales)->toBe(80_000);
});

it('files a bill by when it was paid, not when it was opened', function (): void {
    // Opened before midnight, settled after: it belongs to the new day, which
    // is the drawer that actually took the money.
    $order = settled($this->outlet, 40_000, CarbonImmutable::now()->startOfDay()->addMinutes(10));
    $order->forceFill(['placed_at' => CarbonImmutable::now()->subDay()->setTime(23, 50)])->save();

    expect($this->report->summary($this->outlet, $this->today)->orders)->toBe(1);
});

it('ignores open, cancelled and merged bills', function (): void {
    settled($this->outlet, 50_000);

    Order::factory()->for($this->outlet)->create([
        'status' => OrderStatus::Open,
        'grand_total' => 70_000,
    ]);

    $keeper = settled($this->outlet, 20_000);

    Order::factory()->for($this->outlet)->create([
        'status' => OrderStatus::Paid,
        'grand_total' => 15_000,
        'paid_at' => now(),
        'merged_into_order_id' => $keeper->getKey(),
    ]);

    expect($this->report->summary($this->outlet, $this->today)->netSales)->toBe(70_000);
});

it('takes tax and service charge out of net sales', function (): void {
    Order::factory()->for($this->outlet)->create([
        'status' => OrderStatus::Paid,
        'grand_total' => 123_200,
        'tax_total' => 11_000,
        'service_charge_total' => 5_000,
        'rounding' => -200,
        'paid_at' => now(),
    ]);

    $summary = $this->report->summary($this->outlet, $this->today);

    expect($summary->gross)->toBe(123_200)
        ->and($summary->netSales)->toBe(107_400);
});

it('reports gross profit from the cost snapshot on the line', function (): void {
    settled($this->outlet, 100_000, cost: 40_000);

    $summary = $this->report->summary($this->outlet, $this->today);

    expect($summary->cost)->toBe(40_000)
        ->and($summary->grossProfit())->toBe(60_000)
        ->and($summary->marginBps())->toBe(6000);
});

it('leaves voided lines out of the product figures', function (): void {
    $order = settled($this->outlet, 60_000, cost: 10_000);

    OrderItem::factory()->for($order)->create([
        'product_name' => 'Dibatalkan',
        'line_total' => 25_000,
        'cost_total' => 9_000,
        'status' => OrderItemStatus::Voided,
    ]);

    $names = $this->report->topProducts($this->outlet, $this->today)->pluck('name');

    expect($names)->not->toContain('Dibatalkan')
        ->and($this->report->summary($this->outlet, $this->today)->cost)->toBe(10_000);
});

it('shows a quiet day as a zero rather than a gap', function (): void {
    settled($this->outlet, 30_000, CarbonImmutable::now()->subDays(2));

    $days = $this->report->daily(
        $this->outlet,
        DateRange::of(now()->subDays(2), now()),
    );

    expect($days)->toHaveCount(3)
        ->and($days->first()->net)->toBe(30_000)
        ->and($days->last()->net)->toBe(0)
        ->and($days->last()->orders)->toBe(0);
});

it('ranks products by revenue', function (): void {
    $order = settled($this->outlet, 10_000);
    $order->items()->delete();

    OrderItem::factory()->for($order)->create(['product_name' => 'Kopi Susu', 'quantity' => 3, 'line_total' => 54_000]);
    OrderItem::factory()->for($order)->create(['product_name' => 'Es Teh', 'quantity' => 2, 'line_total' => 16_000]);

    $top = $this->report->topProducts($this->outlet, $this->today);

    expect($top->first()->name)->toBe('Kopi Susu')
        ->and($top->first()->net)->toBe(54_000)
        ->and($top->first()->quantity)->toBe(3.0);
});

it('keeps another outlet out of this outlet report', function (): void {
    settled($this->outlet, 25_000);
    settled(Outlet::factory()->create(), 90_000);

    expect($this->report->summary($this->outlet, $this->today)->netSales)->toBe(25_000);
});
