<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Reporting\DateRange;
use App\Domain\Reporting\ExportSales;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();

    $order = Order::factory()->for($this->outlet)->create([
        'status' => OrderStatus::Paid,
        'grand_total' => 100_000,
        'tax_total' => 0,
        'service_charge_total' => 0,
        'rounding' => 0,
        'paid_at' => now(),
    ]);

    OrderItem::factory()->for($order)->create([
        'product_name' => 'Kopi Susu Gula Aren',
        'line_total' => 100_000,
        'cost_total' => 35_000,
    ]);
});

/**
 * Sign in at the outlet with a role and open the report.
 */
function openReport(Outlet $outlet, Role $role): Testable
{
    $user = User::factory()->atOutlet($outlet, $role)->create();

    test()->actingAs($user);
    app(CurrentOutlet::class)->set($outlet, $user);

    return Livewire::test('pages::reports.index');
}

it('shows a manager the margin', function (): void {
    openReport($this->outlet, Role::Manager)
        ->assertOk()
        ->assertSee(__('reports.gross_profit'))
        ->assertSee('65.000')
        ->assertSee('Kopi Susu Gula Aren');
});

it('keeps the cost figures away from a cashier', function (): void {
    openReport($this->outlet, Role::Cashier)
        ->assertOk()
        ->assertSee(__('reports.net_sales'))
        ->assertDontSee(__('reports.gross_profit'));
});

it('refuses to export for a cashier', function (): void {
    openReport($this->outlet, Role::Cashier)
        ->call('export')
        ->assertForbidden();
});

it('exports the daily figures as a semicolon separated file', function (): void {
    $response = app(ExportSales::class)->handle($this->outlet, DateRange::of(now(), now()));

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    // Semicolons, because an Indonesian Excel reads a comma as a decimal point
    // and would fold the whole row into one cell.
    expect($csv)->toContain(__('reports.net_sales'))
        ->toContain('100000')
        ->toContain(';')
        ->and($response->headers->get('content-disposition'))->toContain('.csv');
});

it('switches the range without losing the page', function (): void {
    openReport($this->outlet, Role::Manager)
        ->call('usePreset', 'yesterday')
        ->assertSet('preset', 'yesterday')
        // Yesterday sold nothing, so the page says so rather than showing a
        // table of zeroes.
        ->assertSee(__('reports.empty_heading'));
});

it('falls back to today when the typed dates make no sense', function (): void {
    openReport($this->outlet, Role::Manager)
        ->set('from', 'bukan tanggal')
        ->set('to', 'juga bukan')
        ->assertOk()
        ->assertSee(__('reports.net_sales'));
});
