<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);

    $this->cash = PaymentMethod::create([
        'name' => 'Tunai',
        'type' => 'cash',
        'opens_cash_drawer' => true,
        'needs_reference' => false,
        'fee_percent' => 0,
    ]);
});

it('opens a shift from the counted float', function (): void {
    Livewire::test('pages::pos.shift')
        ->set('openingCash', '200.000')
        ->call('open')
        ->assertSet('openingCash', '');

    expect(CashierShift::query()->sole()->opening_cash)->toBe(200000);
});

it('shows the drawer count a cashier has to match', function (): void {
    $shift = app(ShiftRegister::class)->open($this->outlet, $this->cashier, 100000);

    $order = Order::factory()->for($this->outlet)->create([
        'user_id' => $this->cashier->id,
        'cashier_shift_id' => $shift->id,
        'grand_total' => 45000,
    ]);

    app(TakePayment::class)->handle($order, $this->cash, $this->cashier, 45000, tendered: 50000);

    Livewire::test('pages::pos.shift')
        ->assertSet('expected', 145000)
        ->set('countedCash', '140.000')
        ->assertSet('difference', -5000)
        ->assertSee(__('shifts.short'));
});

it('closes the shift with the counted cash and a note', function (): void {
    $shift = app(ShiftRegister::class)->open($this->outlet, $this->cashier, 100000);

    Livewire::test('pages::pos.shift')
        ->set('countedCash', '95.000')
        ->set('note', 'Kurang kembalian')
        ->call('close')
        ->assertSet('countedCash', '');

    $shift->refresh();

    expect($shift->isOpen())->toBeFalse()
        ->and($shift->cash_difference)->toBe(-5000)
        ->and($shift->note)->toBe('Kurang kembalian');
});

it('ties a bill rung up during a shift to that shift', function (): void {
    $shift = app(ShiftRegister::class)->open($this->outlet, $this->cashier, 0);

    $product = Product::factory()
        ->for(Category::factory())
        ->create();

    ProductVariant::factory()
        ->for($product)
        ->create(['base_price' => 15000, 'is_default' => true]);

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $product->id)
        ->set('orderType', 'take_away')
        ->call('saveBill');

    expect(Order::query()->sole()->cashier_shift_id)->toBe($shift->id);
});
