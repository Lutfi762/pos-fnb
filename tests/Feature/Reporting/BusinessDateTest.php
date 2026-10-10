<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\BusinessCalendar;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\LogWaste;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Reporting\DailySummaries;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\Enums\Preset;
use App\Domain\Reporting\Models\DailySalesSummary;
use App\Domain\Reporting\SalesReport;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\RefundOrder;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00'));

    $this->outlet = Outlet::factory()->create(['tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();
    $this->cash = PaymentMethod::create(['name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0]);
    $this->card = PaymentMethod::create(['name' => 'Kartu', 'type' => 'card', 'opens_cash_drawer' => false, 'needs_reference' => false, 'fee_percent' => 1]);

    $this->calendar = app(BusinessCalendar::class);
    $this->report = app(SalesReport::class);

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

/**
 * A bill rung up and paid in full through the till, at a given moment.
 */
function payBill(object $test, int $total, string $at, ?PaymentMethod $method = null, int $cost = 0): Order
{
    $order = Order::factory()->for($test->outlet)->create([
        'user_id' => $test->cashier->id,
        'subtotal' => $total,
        'grand_total' => $total,
        'tax_total' => 0,
        'service_charge_total' => 0,
        'rounding' => 0,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Kopi', 'variant_name' => 'Reguler',
        'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'cost_total' => $cost,
    ]);

    app(TakePayment::class)->handle($order, $method ?? $test->cash, $test->cashier, $total, tendered: $total, paidAt: CarbonImmutable::parse($at));

    return $order->refresh();
}

function day(object $test, string $date): DailySalesSummary
{
    return DailySalesSummary::query()->where('outlet_id', $test->outlet->id)->where('business_date', $date)->sole();
}

it('starts the trading day at the outlet hour, not at midnight', function (string $moment, string $expected): void {
    expect($this->calendar->dateFor($this->outlet, CarbonImmutable::parse($moment)))->toBe($expected);
})->with([
    'late night still yesterday' => ['2026-03-10 01:30', '2026-03-09'],
    'one second before the start' => ['2026-03-10 03:59:59', '2026-03-09'],
    'the start itself' => ['2026-03-10 04:00', '2026-03-10'],
    'evening' => ['2026-03-10 23:59', '2026-03-10'],
]);

it('reads the outlet clock in its own timezone', function (): void {
    $makassar = Outlet::factory()->create(['timezone' => 'Asia/Makassar', 'day_starts_at' => 4]);
    $midnight = Outlet::factory()->create(['day_starts_at' => 0]);

    // 03:30 in Jakarta is 04:30 in Makassar: past the start there.
    expect($this->calendar->dateFor($makassar, CarbonImmutable::parse('2026-03-10 03:30')))->toBe('2026-03-10')
        ->and($this->calendar->dateFor($this->outlet, CarbonImmutable::parse('2026-03-10 03:30')))->toBe('2026-03-09')
        ->and($this->calendar->dateFor($midnight, CarbonImmutable::parse('2026-03-10 00:01')))->toBe('2026-03-10');

    [$from, $to] = $this->calendar->bounds($makassar, '2026-03-10');

    expect($from->toDateTimeString())->toBe('2026-03-10 03:00:00')
        ->and($to->toDateTimeString())->toBe('2026-03-11 02:59:59');
});

it('stamps a bill with its trading day when it is paid, including a sale synced late', function (): void {
    $lateNight = payBill($this, 40_000, '2026-03-10 01:30');
    $offline = payBill($this, 25_000, '2026-03-08 20:00');

    expect($lateNight->business_date)->toBe('2026-03-09')
        ->and($offline->business_date)->toBe('2026-03-08')
        ->and(day($this, '2026-03-09')->net_sales)->toBe(40_000)
        ->and(day($this, '2026-03-08')->net_sales)->toBe(25_000);
});

it('keeps the summary in step with every bill, refund, and waste entry of the day', function (): void {
    payBill($this, 50_000, '2026-03-10 09:00', cost: 20_000);
    $card = payBill($this, 30_000, '2026-03-10 10:00', $this->card, cost: 9_000);

    $milk = InventoryItem::factory()->create(['average_cost' => 20]);
    app(LogWaste::class)->handle($this->outlet, $milk, 250, 'spilled', null, $this->cashier);

    app(RefundOrder::class)->handle($card, $this->cashier, $this->manager, [$card->items()->sole()->id => 1], 'Salah', false, $this->cash);

    $summary = day($this, '2026-03-10');

    expect($summary->orders)->toBe(2)
        ->and($summary->net_sales)->toBe(80_000)
        ->and($summary->cost)->toBe(29_000)
        ->and($summary->cash)->toBe(50_000)
        ->and($summary->non_cash)->toBe(30_000)
        ->and($summary->fees)->toBe(300)
        ->and($summary->refunds)->toBe(1)
        ->and($summary->refunded)->toBe(30_000)
        ->and($summary->refunded_net)->toBe(30_000)
        ->and($summary->waste)->toBe(5_000);

    $report = $this->report->summary($this->outlet, DateRange::of('2026-03-10', '2026-03-10'));

    expect($report->netAfterRefunds())->toBe(50_000)
        ->and($report->waste)->toBe(5_000)
        ->and($report->grossProfit())->toBe(51_000);
});

it('dates a refund on the day the money went back and leaves the old day alone', function (): void {
    $order = payBill($this, 60_000, '2026-03-07 13:00');
    $before = day($this, '2026-03-07')->only(['orders', 'net_sales', 'refunded']);

    app(RefundOrder::class)->handle($order, $this->cashier, $this->manager, [$order->items()->sole()->id => 1], 'Basi', false, $this->cash);

    expect(day($this, '2026-03-07')->only(['orders', 'net_sales', 'refunded']))->toBe($before)
        ->and(day($this, '2026-03-10')->refunded)->toBe(60_000)
        ->and($order->refunds()->sole()->business_date)->toBe('2026-03-10');
});

it('builds a day nobody touched when a report first asks for it, and zero for a quiet one', function (): void {
    payBill($this, 45_000, '2026-03-05 12:00');
    DailySalesSummary::query()->delete();

    $days = $this->report->daily($this->outlet, DateRange::of('2026-03-04', '2026-03-06'));

    expect($days->pluck('net')->all())->toBe([0, 45_000, 0])
        ->and(DailySalesSummary::query()->count())->toBe(3);
});

it('never builds a summary for a day that has not happened yet', function (): void {
    $this->report->daily($this->outlet, DateRange::of('2026-03-09', '2026-03-12'));

    expect(DailySalesSummary::query()->pluck('business_date')->map(fn ($date) => substr((string) $date, 0, 10))->sort()->values()->all())
        ->toBe(['2026-03-09', '2026-03-10']);
});

it('rebuilds to exactly the same numbers, any number of times', function (): void {
    payBill($this, 50_000, '2026-03-09 12:00', cost: 10_000);
    payBill($this, 20_000, '2026-03-10 02:00', $this->card);
    payBill($this, 35_000, '2026-03-10 12:00');

    $columns = ['outlet_id', 'business_date', 'orders', 'gross', 'net_sales', 'cost', 'cash', 'non_cash', 'fees', 'refunded', 'waste'];
    $live = DailySalesSummary::query()->orderBy('business_date')->get($columns)->toArray();

    DailySalesSummary::query()->delete();
    $this->artisan('meja:rebuild-summaries')->assertSuccessful();
    $this->artisan('meja:rebuild-summaries', ['--from' => '2026-03-09', '--to' => '2026-03-10'])->assertSuccessful();

    expect(DailySalesSummary::query()->orderBy('business_date')->get($columns)->toArray())->toBe($live)
        ->and(collect($live)->pluck('net_sales')->all())->toBe([70_000, 35_000]);
});

it('shows the trading day in progress as today on the report', function (): void {
    // 02:00: the night has not ended yet, so "today" is still the 9th.
    $this->travelTo(CarbonImmutable::parse('2026-03-10 02:00'));
    payBill($this, 42_000, '2026-03-10 01:15');

    expect(Preset::Today->range($this->calendar->today($this->outlet))->fromDate())->toBe('2026-03-09');

    $this->actingAs($this->manager);

    Livewire::test('pages::reports.index')->assertSee('42.000');
});

it('dates shifts by the trading day they opened in', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 03:00'));

    expect(app(ShiftRegister::class)->open($this->outlet, $this->cashier, 100_000)->business_date)->toBe('2026-03-09');
});

it('lets a manager move the start of the day, from the next sale on', function (): void {
    $before = payBill($this, 10_000, '2026-03-10 02:30');

    $this->actingAs($this->manager);

    Livewire::test('pages::outlet.settings')
        ->assertSet('dayStartsAt', 4)
        ->set('timezone', 'Asia/Makassar')
        ->set('dayStartsAt', 2)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->outlet->refresh()->getAttribute('day_starts_at'))->toBe(2)
        ->and(AuditLog::query()->where('action', 'outlet.clock_changed')->count())->toBe(1);

    // 02:30 Jakarta is 03:30 Makassar: past a 02:00 start now.
    $after = payBill($this, 10_000, '2026-03-10 02:30');

    expect($before->refresh()->business_date)->toBe('2026-03-09')
        ->and($after->business_date)->toBe('2026-03-10');

    $this->artisan('meja:rebuild-summaries', ['--redate' => true, '--outlet' => $this->outlet->code])->assertSuccessful();

    expect($before->refresh()->business_date)->toBe('2026-03-10')
        ->and(day($this, '2026-03-10')->orders)->toBe(2);
});

it('refuses clocks outside Indonesia and keeps cashiers out of the settings', function (): void {
    $this->get(route('outlet.settings'))->assertForbidden();

    $this->actingAs($this->manager);

    Livewire::test('pages::outlet.settings')
        ->set('timezone', 'Europe/London')
        ->set('dayStartsAt', 11)
        ->call('save')
        ->assertHasErrors(['timezone', 'dayStartsAt']);
});

it('counts a summary per outlet', function (): void {
    payBill($this, 10_000, '2026-03-10 12:00');

    $other = Outlet::factory()->create();

    expect(app(DailySummaries::class)->forRange($other, DateRange::of('2026-03-10', '2026-03-10'))->first()->net_sales)->toBe(0)
        ->and(day($this, '2026-03-10')->net_sales)->toBe(10_000);
});
