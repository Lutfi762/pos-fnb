<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Enums\OrderType;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create([
        'tax_percent' => 0,
        'service_charge_percent' => 0,
        'cash_rounding' => 0,
    ]);

    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);

    $this->cash = PaymentMethod::create([
        'name' => 'Tunai',
        'type' => 'cash',
        'opens_cash_drawer' => true,
        'needs_reference' => false,
        'fee_percent' => 0,
        'sort_order' => 1,
    ]);

    $this->card = PaymentMethod::create([
        'name' => 'Kartu',
        'type' => 'card',
        'opens_cash_drawer' => false,
        'needs_reference' => true,
        'fee_percent' => 1,
        'sort_order' => 2,
    ]);

    $this->order = Order::factory()->for($this->outlet)->create([
        'user_id' => $this->cashier->id,
        'subtotal' => 47000,
        'grand_total' => 47000,
    ]);
});

it('opens the payment screen with the bill prefilled', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->assertSet('amount', '47.000')
        ->assertSet('methodId', $this->cash->id)
        ->assertSee('Tunai');
});

it('offers the next whole notes above what is owed', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->assertSet('quickAmounts', [50000, 60000, 100000]);
});

it('shows the change before the drawer opens', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('amount', '100.000')
        ->assertSet('change', 53000);
});

it('sends the cashier to the receipt once the bill is settled', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('amount', '50.000')
        ->call('take')
        ->assertRedirect(route('pos.receipt', ['order' => $this->order]));

    $this->order->refresh();

    expect($this->order->status)->toBe(OrderStatus::Paid)
        ->and($this->order->change_total)->toBe(3000);
});

it('keeps the cashier on the screen for the rest of a split bill', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('amount', '20.000')
        ->call('take')
        ->assertNoRedirect()
        ->assertSet('amount', '27.000')
        ->assertSet('outstanding', 27000);
});

it('asks for a reference before charging a card', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('methodId', $this->card->id)
        ->call('take')
        ->assertHasErrors(['reference' => 'required']);

    expect($this->order->refresh()->paid_total)->toBe(0);
});

it('refuses an empty amount', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('amount', '')
        ->call('take')
        ->assertHasErrors('amount');
});

it('walks back a mis-keyed tender', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('amount', '20.000')
        ->call('take')
        ->call('voidLast')
        ->assertSet('outstanding', 47000)
        ->assertSet('amount', '47.000');
});

it('warns when the bill belongs to no open drawer', function (): void {
    $this->get(route('pos.payment', ['order' => $this->order]))
        ->assertOk()
        ->assertSee(__('payments.no_shift'));
});

it('keeps a bill from another outlet off the screen', function (): void {
    $other = Order::factory()->create(['grand_total' => 10000]);

    $this->get(route('pos.payment', ['order' => $other]))->assertForbidden();
});

it('sends the pay button straight to the payment screen', function (): void {
    $product = Product::factory()->for(Category::factory())->create(['name' => 'Kopi']);
    ProductVariant::factory()->for($product)->create(['base_price' => 20000, 'is_default' => true]);

    Livewire::test('pages::pos.index')
        ->call('tapProduct', $product->id)
        ->set('orderType', OrderType::TakeAway->value)
        ->call('saveBill', true)
        ->assertHasNoErrors()
        ->assertRedirectContains('/pay');
});
