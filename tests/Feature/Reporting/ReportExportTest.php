<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\LogWaste;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\MoveStock;
use App\Domain\Reporting\DashboardMetrics;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\Models\ReportExport;
use App\Domain\Reporting\ReportCatalog;
use App\Domain\Reporting\ReportExports;
use App\Domain\Reporting\SalesReport;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\RefundOrder;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use App\Support\Pdf\PdfEngine;
use App\Support\Pdf\PdfRenderer;
use App\Support\Spreadsheet\XlsxReader;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 14:00'));
    Storage::fake('local');

    $this->outlet = Outlet::factory()->create(['name' => 'Meja Menteng', 'code' => 'MTG', 'tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create();
    $this->cash = PaymentMethod::create(['name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0, 'sort_order' => 1]);
    $this->card = PaymentMethod::create(['name' => 'Kartu', 'type' => 'card', 'opens_cash_drawer' => false, 'needs_reference' => false, 'fee_percent' => 1, 'sort_order' => 2]);

    $coffee = Category::factory()->create(['name' => 'Kopi']);
    $this->latte = ProductVariant::factory()->for(Product::factory()->for($coffee)->state(['name' => 'Latte']))->create(['base_price' => 30_000]);

    app(CurrentOutlet::class)->set($this->outlet);
    $this->today = DateRange::of('2026-03-10', '2026-03-10');
});

/**
 * A bill for one line, paid at a given moment.
 */
function exportSale(object $test, int $total, string $at, ?PaymentMethod $method = null, int $cost = 0, string $product = 'Latte', ?Outlet $outlet = null): Order
{
    $order = Order::factory()->for($outlet ?? $test->outlet)->create([
        'user_id' => $test->cashier->id, 'subtotal' => $total, 'grand_total' => $total,
        'tax_total' => 0, 'service_charge_total' => 0, 'rounding' => 0,
    ]);

    OrderItem::create([
        'order_id' => $order->id, 'product_variant_id' => $product === 'Latte' ? $test->latte->id : null,
        'product_name' => $product, 'variant_name' => 'Reguler',
        'quantity' => 1, 'unit_price' => $total, 'line_total' => $total, 'cost_total' => $cost,
    ]);

    app(TakePayment::class)->handle($order, $method ?? $test->cash, $test->cashier, $total, tendered: $total, paidAt: CarbonImmutable::parse($at));

    return $order->refresh();
}

it('totals the daily report to the same net sales the dashboard and tiles show', function (): void {
    exportSale($this, 50_000, '2026-03-10 09:00', cost: 15_000);
    exportSale($this, 30_000, '2026-03-10 11:00', $this->card, cost: 9_000);

    $table = app(ReportCatalog::class)->daily($this->outlet, $this->today);
    $net = array_search(__('reports.net_sales'), array_map(fn ($column) => $column->label, $table->columns), true);

    expect($table->totals[$net])->toBe(80_000)
        ->and(app(SalesReport::class)->summary($this->outlet, $this->today)->netSales)->toBe(80_000)
        ->and(app(DashboardMetrics::class)->pace($this->outlet)['current']->netSales)->toBe(80_000)
        ->and($table->totals[0])->toBe(__('reports.total'));
});

it('breaks sales down by category and product, hiding cost from cashiers', function (): void {
    exportSale($this, 30_000, '2026-03-10 09:00', cost: 9_000);
    exportSale($this, 30_000, '2026-03-10 10:00', cost: 9_000);
    exportSale($this, 20_000, '2026-03-10 10:30', cost: 12_000, product: 'Roti');

    $catalog = app(ReportCatalog::class);
    $table = $catalog->products($this->outlet, $this->today);

    expect($table->rows[0])->toBe(['Kopi', 'Latte', 2.0, 60_000, 18_000, 42_000, 70.0])
        ->and($table->rows[1])->toBe([__('reports.uncategorised'), 'Roti', 1.0, 20_000, 12_000, 8_000, 40.0])
        ->and($table->totals)->toBe([__('reports.total'), null, 3.0, 80_000, 30_000, 50_000, 62.5])
        ->and($catalog->products($this->outlet, $this->today, withCost: false)->columns)->toHaveCount(4);
});

it('takes refunds and waste out of gross profit', function (): void {
    $order = exportSale($this, 60_000, '2026-03-10 09:00', cost: 20_000);
    app(RefundOrder::class)->handle($order, $this->cashier, $this->manager, [$order->items()->sole()->id => 1], 'Salah', false, $this->cash);

    $milk = InventoryItem::factory()->create(['average_cost' => 20]);
    app(LogWaste::class)->handle($this->outlet, $milk, 100, 'spilled', null, $this->cashier);

    exportSale($this, 40_000, '2026-03-10 10:00', cost: 10_000);

    $row = app(ReportCatalog::class)->grossProfit($this->outlet, $this->today)->rows[0];

    // 100.000 sold, 60.000 of it given back, 30.000 cost, 2.000 waste.
    expect(array_slice($row, 1))->toBe([100_000, 60_000, 30_000, 2_000, 8_000, 20.0]);
});

it('follows cash in and out of the outlet for the day', function (): void {
    $shift = app(ShiftRegister::class)->open($this->outlet, $this->cashier, 200_000);

    $cashSale = exportSale($this, 50_000, '2026-03-10 09:00');
    exportSale($this, 40_000, '2026-03-10 10:00', $this->card);
    app(RefundOrder::class)->handle($cashSale, $this->cashier, $this->manager, [$cashSale->items()->sole()->id => 1], 'Basi', false, $this->cash);

    app(ShiftRegister::class)->moveCash($shift, $this->cashier, 'out', 15_000, 'Beli es batu');
    app(ShiftRegister::class)->moveCash($shift, $this->cashier, 'in', 5_000, 'Tambah kembalian');

    $beans = InventoryItem::factory()->create();
    app(MoveStock::class)->record($this->outlet, $beans, StockMovementType::Purchase, 2, $this->manager, unitCost: 120_000);

    $row = app(ReportCatalog::class)->cashFlow($this->outlet, $this->today)->rows[0];

    // 50.000 + 40.000 - 400 fee - 50.000 refund + 5.000 - 15.000 - 240.000 beans.
    expect(array_slice($row, 1))->toBe([50_000, 40_000, 400, 50_000, 0, 5_000, 15_000, 240_000, -210_400]);
});

it('downloads Excel files with real numbers a spreadsheet can add up', function (): void {
    exportSale($this, 50_000, '2026-03-10 09:00', cost: 15_000);
    exportSale($this, 30_000, '2026-03-09 11:00', cost: 9_000);

    $this->actingAs($this->manager);

    $response = $this->get(route('reports.export', ['type' => 'daily', 'format' => 'xlsx', 'from' => '2026-03-09', 'to' => '2026-03-10']))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('Content-Disposition'))->toContain('penjualan-harian-mtg-20260309-20260310.xlsx');

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $response->getContent());

    $rows = (new XlsxReader)->rows($path);
    $info = (new XlsxReader)->rows($path, __('reports.info_sheet'));
    $net = array_search(__('reports.net_sales'), $rows[0], true);

    expect($rows)->toHaveCount(4)
        ->and($rows[1][$net])->toBe(30_000)
        ->and($rows[2][$net])->toBe(50_000)
        ->and($rows[3][0])->toBe(__('reports.total'))
        ->and($rows[3][$net])->toBe(80_000)
        ->and(collect($info)->firstWhere(0, __('reports.outlet'))[1])->toBe('Meja Menteng')
        ->and(AuditLog::query()->where('action', 'report.exported')->sole()->after)->toMatchArray(['type' => 'daily', 'format' => 'xlsx', 'rows' => 2]);
});

it('makes a PDF with an engine, and a printable page without one', function (): void {
    exportSale($this, 50_000, '2026-03-10 09:00');
    $this->actingAs($this->manager);
    $url = route('reports.export', ['type' => 'daily', 'format' => 'pdf', 'from' => '2026-03-10', 'to' => '2026-03-10']);

    app()->instance(PdfRenderer::class, new PdfRenderer);

    $this->get($url)
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee(__('reports.print_hint'))
        ->assertSee('Rp50.000');

    app()->instance(PdfRenderer::class, new PdfRenderer(new class implements PdfEngine
    {
        public function render(string $html, string $paper, string $orientation): string
        {
            return '%PDF-fake '.$orientation.' '.strlen($html);
        }
    }));

    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Twelve columns do not fit a portrait page.
    expect($response->getContent())->toStartWith('%PDF-fake landscape');
});

it('renders a real PDF when dompdf is installed', function (): void {
    exportSale($this, 50_000, '2026-03-10 09:00');
    $this->actingAs($this->manager);

    $pdf = $this->get(route('orders.export', ['order' => Order::query()->sole(), 'format' => 'pdf']))->assertOk()->getContent();

    expect($pdf)->toStartWith('%PDF-');
})->skip(fn (): bool => ! class_exists(Dompdf::class), 'dompdf/dompdf is not installed');

it('keeps report files from cashiers but lets them print one bill', function (): void {
    $order = exportSale($this, 25_000, '2026-03-10 09:00');
    $elsewhere = exportSale($this, 10_000, '2026-03-10 09:00', outlet: Outlet::factory()->create());

    $this->actingAs($this->cashier);

    $this->get(route('reports.export', ['type' => 'daily', 'format' => 'xlsx', 'from' => '2026-03-10', 'to' => '2026-03-10']))->assertForbidden();

    $this->get(route('orders.export', ['order' => $order, 'format' => 'xlsx']))->assertOk();
    $this->get(route('orders.export', ['order' => $order, 'format' => 'pdf']))->assertOk()->assertSee($order->number);
    $this->get(route('orders.export', ['order' => $elsewhere, 'format' => 'pdf']))->assertNotFound();

    expect(AuditLog::query()->where('action', 'order.exported')->count())->toBe(2);
});

it('writes one bill to Excel with its lines, payments, and totals', function (): void {
    $order = exportSale($this, 25_000, '2026-03-10 09:00');
    $this->actingAs($this->cashier);

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $this->get(route('orders.export', ['order' => $order, 'format' => 'xlsx']))->getContent());

    $lines = (new XlsxReader)->rows($path, __('orders.line'));
    $payments = (new XlsxReader)->rows($path, __('orders.payments'));

    expect($lines[1][0])->toBe('Latte')
        ->and($lines[1][6])->toBe(25_000)
        ->and($payments[1][0])->toBe('Tunai')
        ->and($payments[1][1])->toBe(25_000);
});

it('sends big exports through the queue and keeps the file a day', function (): void {
    config(['reports.queue_threshold' => 1]);
    exportSale($this, 10_000, '2026-03-10 09:00');
    exportSale($this, 20_000, '2026-03-10 10:00');

    $this->actingAs($this->manager);

    $this->get(route('reports.export', ['type' => 'orders', 'format' => 'xlsx', 'from' => '2026-03-10', 'to' => '2026-03-10']))
        ->assertRedirect(route('reports.index'))
        ->assertSessionHas('export_queued');

    $export = ReportExport::query()->sole();

    expect($export->status)->toBe(ReportExport::DONE)
        ->and($export->rows)->toBe(2)
        ->and($export->downloadable())->toBeTrue();

    Livewire::test('pages::reports.index')->assertSee(__('reports.exports_heading'))->assertSeeHtml('data-test="export-download"');

    $this->get(route('reports.exports.download', $export))->assertOk()->assertDownload();

    $someoneElse = User::factory()->atOutlet($this->outlet, Role::Manager)->create();
    $this->actingAs($someoneElse)->get(route('reports.exports.download', $export))->assertNotFound();

    $this->travel(25)->hours();
    $this->artisan('meja:prune-exports')->assertSuccessful();

    expect($export->refresh()->status)->toBe(ReportExport::EXPIRED);
    Storage::disk('local')->assertDirectoryEmpty('exports');

    $this->actingAs($this->manager)->get(route('reports.exports.download', $export))->assertStatus(410);
});

it('refuses ranges longer than a year in one file', function (): void {
    $this->actingAs($this->manager);

    $this->get(route('reports.export', ['type' => 'daily', 'format' => 'xlsx', 'from' => '2024-01-01', 'to' => '2026-03-10']))->assertStatus(422);
});

it('shows management reports to managers only, and remembers the range', function (): void {
    exportSale($this, 50_000, '2026-03-10 09:00', cost: 20_000);

    $this->actingAs($this->manager);

    Livewire::test('pages::reports.index')
        ->call('useTab', 'gross_profit')
        ->assertSee(__('reports.types.gross_profit'))
        ->assertSeeHtml('data-test="report-table"')
        ->assertSee('Rp30.000')
        ->call('usePreset', 'month');

    Livewire::test('pages::reports.index')->assertSet('preset', 'month');

    $this->actingAs($this->cashier);

    Livewire::test('pages::reports.index')
        ->assertDontSeeHtml('data-test="tab-gross_profit"')
        ->assertDontSeeHtml('data-test="export-menu"')
        ->call('useTab', 'products')
        ->assertSee('Latte')
        ->assertDontSee(__('reports.cost'))
        ->call('useTab', 'cash_flow')
        ->assertForbidden();
});

it('lists bills with filters for managers and today only for cashiers', function (): void {
    $yesterday = exportSale($this, 10_000, '2026-03-09 12:00');
    $today = exportSale($this, 20_000, '2026-03-10 12:00');
    $open = Order::factory()->for($this->outlet)->create(['user_id' => $this->cashier->id, 'grand_total' => 5_000, 'placed_at' => now()]);

    $this->actingAs($this->manager);

    Livewire::test('pages::orders.index')
        ->set('from', '2026-03-09')
        ->set('to', '2026-03-10')
        ->assertSee($yesterday->number)
        ->assertSee($today->number)
        ->assertSee($open->number)
        ->set('status', 'open')
        ->assertDontSee($today->number)
        ->assertSee($open->number)
        ->set('status', 'all')
        ->set('search', substr($today->number, -6))
        ->assertSee($today->number)
        ->assertDontSee($yesterday->number)
        ->assertSeeHtml('data-test="orders-export"');

    $this->actingAs($this->cashier);

    Livewire::test('pages::orders.index')
        ->set('from', '2026-03-09')
        ->assertSee($today->number)
        ->assertDontSee($yesterday->number)
        ->assertDontSeeHtml('data-test="orders-export"');
});

it('exports the filtered bills list', function (): void {
    exportSale($this, 10_000, '2026-03-10 09:00');
    exportSale($this, 20_000, '2026-03-10 10:00', $this->card);

    $this->actingAs($this->manager);

    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $this->get(route('reports.export', ['type' => 'orders', 'format' => 'xlsx', 'from' => '2026-03-10', 'to' => '2026-03-10', 'method' => 'Kartu']))->getContent());

    $rows = (new XlsxReader)->rows($path);
    $total = array_search(__('reports.total'), $rows[0], true);

    expect($rows)->toHaveCount(3)
        ->and($rows[1][$total])->toBe(20_000)
        ->and($rows[2][$total])->toBe(20_000);

    expect(app(ReportExports::class)->basename('orders', $this->outlet, $this->today))->toBe('daftar-transaksi-mtg-20260310-20260310');
});
