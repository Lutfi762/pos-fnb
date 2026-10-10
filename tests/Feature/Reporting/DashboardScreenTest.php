<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\MoveStock;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    // Tuesday 10 March 2026, 14:00.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 14:00'));

    $this->outlet = Outlet::factory()->create(['name' => 'Meja Menteng', 'tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create(['name' => 'Sari Kasir']);
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create(['name' => 'Budi Manajer']);
    $this->cash = PaymentMethod::create(['name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0]);

    app(CurrentOutlet::class)->set($this->outlet);
});

function sale(object $test, int $total, string $at, string $product = 'Kopi Susu', ?Outlet $outlet = null): Order
{
    $order = Order::factory()->for($outlet ?? $test->outlet)->create([
        'user_id' => $test->cashier->id, 'subtotal' => $total, 'grand_total' => $total,
        'tax_total' => 0, 'service_charge_total' => 0, 'rounding' => 0,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_name' => $product, 'variant_name' => 'Reguler',
        'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'cost_total' => (int) ($total * 0.3),
    ]);

    app(TakePayment::class)->handle($order, $test->cash, $test->cashier, $total, tendered: $total, paidAt: CarbonImmutable::parse($at));

    return $order;
}

it('compares today so far with last Tuesday up to the same minute', function (): void {
    sale($this, 40_000, '2026-03-03 11:00');
    // Later in the day last week: not yet comparable at 14:00.
    sale($this, 90_000, '2026-03-03 19:00');

    sale($this, 60_000, '2026-03-10 09:30');

    ['current' => $current, 'previous' => $previous] = app(DashboardMetrics::class)->pace($this->outlet);

    expect($current->netSales)->toBe(60_000)
        ->and($previous->netSales)->toBe(40_000);

    $this->actingAs($this->manager);

    Livewire::test('pages::dashboard')
        ->assertSee('Selamat siang, Budi')
        ->assertSeeHtml('data-test="kpi-net"')
        ->assertSee('Rp60.000')
        ->assertSee('naik 50,0%')
        ->assertSee(__('dashboard.kpi.gross_profit'));
});

it('says plainly when there is nothing last week to compare with', function (): void {
    sale($this, 60_000, '2026-03-10 09:30');

    $this->actingAs($this->manager);

    Livewire::test('pages::dashboard')
        ->assertSee(__('dashboard.kpi.new'))
        ->assertDontSee(__('dashboard.kpi.vs', ['day' => 'Selasa']));
});

it('keeps cost, staff, and system figures away from a cashier', function (): void {
    sale($this, 30_000, '2026-03-10 10:00');

    $this->actingAs($this->cashier);

    Livewire::test('pages::dashboard')
        ->assertSee(__('dashboard.kpi.net_sales'))
        ->assertSee('Kopi Susu')
        ->assertDontSee(__('dashboard.kpi.gross_profit'))
        ->assertDontSee(__('dashboard.cashiers.title'))
        ->assertDontSee(__('dashboard.status.title'))
        ->assertDontSee(__('dashboard.stock.title'));
});

it('lays out the last seven trading days against the week before', function (): void {
    sale($this, 50_000, '2026-03-04 12:00');
    sale($this, 20_000, '2026-02-25 12:00');
    sale($this, 15_000, '2026-03-10 12:00');

    $week = app(DashboardMetrics::class)->week($this->outlet);

    expect($week)->toHaveCount(7)
        ->and($week[0]['date'])->toBe('2026-03-04')
        ->and($week[0]['net'])->toBe(50_000)
        ->and($week[0]['previous'])->toBe(20_000)
        ->and($week[6]['current'])->toBeTrue()
        ->and($week[6]['net'])->toBe(15_000)
        ->and(collect($week)->where('current', true))->toHaveCount(1);
});

it('draws the rush hours in the outlet clock, late nights in the night they belong to', function (): void {
    foreach (['2026-03-03 12:10', '2026-03-03 12:40', '2026-03-10 12:05', '2026-03-09 08:00'] as $at) {
        sale($this, 10_000, $at);
    }

    // Saturday night, 01:30 on Sunday: Saturday's row.
    sale($this, 10_000, '2026-03-08 01:30');

    $heat = app(DashboardMetrics::class)->heatmap($this->outlet);
    $tuesday = collect($heat['rows'])->firstWhere('weekday', 2);
    $saturday = collect($heat['rows'])->firstWhere('weekday', 6);

    expect($heat['peak'])->toBe(['weekday' => $tuesday['label'], 'hour' => 12, 'orders' => 3])
        ->and(collect($tuesday['cells'])->firstWhere('hour', 12)['level'])->toBe(4)
        ->and(collect($saturday['cells'])->firstWhere('hour', 1)['orders'])->toBe(1)
        // From the first busy hour to the last, in trading order: 08 ... 01.
        ->and($heat['hours'][0])->toBe(8)
        ->and(end($heat['hours']))->toBe(1);
});

it('lists stock at or under its reorder point, emptiest first', function (): void {
    $beans = InventoryItem::factory()->create(['name' => 'Biji kopi']);
    $milk = InventoryItem::factory()->create(['name' => 'Susu']);
    $sugar = InventoryItem::factory()->create(['name' => 'Gula']);

    app(MoveStock::class)->record($this->outlet, $beans, StockMovementType::Purchase, 200, unitCost: 300);
    app(MoveStock::class)->record($this->outlet, $milk, StockMovementType::Purchase, 5000, unitCost: 20);
    app(MoveStock::class)->record($this->outlet, $sugar, StockMovementType::Adjustment, -50);

    StockLevel::query()->where('inventory_item_id', $beans->id)->update(['reorder_point' => 1000]);
    StockLevel::query()->where('inventory_item_id', $milk->id)->update(['reorder_point' => 1000]);

    expect(app(DashboardMetrics::class)->criticalStock($this->outlet)->map(fn ($level) => $level->item->name)->all())
        ->toBe(['Gula', 'Biji kopi']);

    $this->actingAs($this->manager);

    Livewire::test('pages::dashboard')
        ->assertSee(__('dashboard.stock.negative'))
        ->assertSee(__('dashboard.status.negative_stock', ['count' => 1]));
});

it('says all is well when nothing needs attention, and points at what does', function (): void {
    $this->actingAs($this->manager);

    Livewire::test('pages::dashboard')->assertSee(__('dashboard.status.all_good'));

    $standee = PaymentMethod::create(['name' => 'QRIS Statis', 'type' => 'qris', 'mode' => PaymentMethod::STATIC_QRIS, 'opens_cash_drawer' => false, 'needs_reference' => true, 'fee_percent' => 0]);
    $order = Order::factory()->for($this->outlet)->create(['user_id' => $this->cashier->id, 'grand_total' => 10_000, 'subtotal' => 10_000]);
    app(TakePayment::class)->handle($order, $standee, $this->cashier, 10_000, reference: 'RRN 1');

    Livewire::test('pages::dashboard')
        ->assertDontSee(__('dashboard.status.all_good'))
        ->assertSee(__('dashboard.status.static_qris', ['count' => 1]))
        ->assertSeeHtml(route('payments.review'));
});

it('shows an owner every outlet side by side', function (): void {
    $owner = User::factory()->atOutlet($this->outlet, Role::Owner)->create();
    $second = Outlet::factory()->create(['name' => 'Meja Kemang']);

    sale($this, 70_000, '2026-03-10 10:00');
    sale($this, 30_000, '2026-03-10 10:00', outlet: $second);

    $this->actingAs($owner);

    Livewire::test('pages::dashboard')
        ->assertSee(__('dashboard.outlets.title'))
        ->assertSeeInOrder(['Meja Menteng', 'Meja Kemang']);

    $this->actingAs($this->manager);

    Livewire::test('pages::dashboard')->assertDontSee(__('dashboard.outlets.title'));
});

it('hands the chart its new numbers when the page refreshes', function (): void {
    $this->actingAs($this->manager);

    $page = Livewire::test('pages::dashboard');

    sale($this, 25_000, '2026-03-10 13:00');

    $page->call('refresh')
        ->assertDispatched('dashboard-week', fn (string $name, array $params): bool => $params['week'][6]['net'] === 25_000)
        ->assertSee('Rp25.000');
});

it('keeps the full report one click away', function (): void {
    $this->actingAs($this->manager);

    $this->get(route('dashboard'))->assertOk()->assertSee(route('reports.index'));
    $this->get(route('reports.index'))->assertOk()->assertSee(__('reports.title'));
});
