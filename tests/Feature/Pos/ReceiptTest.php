<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\TakePayment;
use App\Models\User;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create([
        'name' => 'Meja Kopi Menteng',
        'receipt_footer' => 'Terima kasih',
        'cash_rounding' => 0,
    ]);

    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);

    $this->order = Order::factory()->for($this->outlet)->create([
        'user_id' => $this->cashier->id,
        'subtotal' => 40000,
        'tax_total' => 4400,
        'tax_percent' => 11,
        'service_charge_total' => 0,
        'service_charge_percent' => 0,
        'grand_total' => 44400,
    ]);

    OrderItem::create([
        'order_id' => $this->order->id,
        'product_name' => 'Kopi Susu',
        'variant_name' => 'Reguler',
        'quantity' => 2,
        'unit_price' => 20000,
        'line_total' => 40000,
    ]);
});

it('prints what the guest was charged and what they handed over', function (): void {
    $cash = PaymentMethod::create([
        'name' => 'Tunai',
        'type' => 'cash',
        'opens_cash_drawer' => true,
        'needs_reference' => false,
        'fee_percent' => 0,
    ]);

    app(TakePayment::class)->handle($this->order, $cash, $this->cashier, 44400, tendered: 50000);

    $this->get(route('pos.receipt', ['order' => $this->order->refresh()]))
        ->assertOk()
        ->assertSee('Meja Kopi Menteng')
        ->assertSee($this->order->number)
        ->assertSee('Kopi Susu')
        ->assertSee('Rp44.400')
        // The tender and the change both show, so the guest can check them.
        ->assertSee('50.000')
        ->assertSee('5.600')
        ->assertSee('Terima kasih');
});

it('keeps a receipt from another outlet out of sight', function (): void {
    $other = Order::factory()->create(['grand_total' => 10000]);

    $this->get(route('pos.receipt', ['order' => $other]))->assertForbidden();
});
