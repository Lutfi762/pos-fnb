<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\MoveStock;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\SavePurchaseOrder;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->keeper = User::factory()->atOutlet($this->outlet, Role::Warehouse)->create();
    $this->supplier = Supplier::factory()->create(['name' => 'CV Kopi Nusantara']);
    $this->beans = InventoryItem::factory()->create(['name' => 'Biji Kopi', 'average_cost' => 0]);

    $this->actingAs($this->keeper);
    app(CurrentOutlet::class)->set($this->outlet);
});

/** An order already sent to the supplier, ready to receive against. */
function placedOrder(object $test): PurchaseOrder
{
    $orders = app(SavePurchaseOrder::class);

    $order = $orders->handle(null, $test->outlet, $test->keeper, [
        ['inventory_item_id' => $test->beans->id, 'quantity' => 10, 'unit_cost' => 120000],
    ], ['supplier_id' => $test->supplier->id]);

    return $orders->place($order)->load('items');
}

it('lets warehouse staff open the purchase list', function (): void {
    placedOrder($this);

    $this->get(route('purchases.index'))
        ->assertOk()
        ->assertSee('CV Kopi Nusantara');
});

it('keeps a cashier out of purchasing', function (): void {
    $cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($cashier)->get(route('purchases.index'))->assertForbidden();
});

it('filters the list by status', function (): void {
    placedOrder($this);

    Livewire::test('pages::purchases.index')
        ->set('status', PurchaseOrderStatus::Draft->value)
        ->assertDontSee('CV Kopi Nusantara')
        ->set('status', PurchaseOrderStatus::Ordered->value)
        ->assertSee('CV Kopi Nusantara');
});

it('hides another outlet\'s orders', function (): void {
    placedOrder($this);

    $other = Outlet::factory()->create();
    $this->keeper->outlets()->attach($other, ['role' => Role::Warehouse->value]);
    app(CurrentOutlet::class)->set($other);

    Livewire::test('pages::purchases.index')->assertDontSee('CV Kopi Nusantara');
});

it('writes a draft order from the form', function (): void {
    Livewire::test('pages::purchases.edit')
        ->set('supplierId', $this->supplier->id)
        ->set('lines.0.inventory_item_id', $this->beans->id)
        ->set('lines.0.quantity', '10')
        ->set('lines.0.unit_cost', '120000')
        ->call('save')
        ->assertHasNoErrors();

    $order = PurchaseOrder::query()->with('items')->firstOrFail();

    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->grand_total)->toBe(1200000)
        ->and($order->items->first()->item_name)->toBe('Biji Kopi');
});

it('refuses a draft with no supplier', function (): void {
    Livewire::test('pages::purchases.edit')
        ->set('lines.0.inventory_item_id', $this->beans->id)
        ->set('lines.0.quantity', '10')
        ->set('lines.0.unit_cost', '120000')
        ->call('save')
        ->assertHasErrors('supplierId');

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('drops the line the buyer removed before saving', function (): void {
    Livewire::test('pages::purchases.edit')
        ->call('addLine')
        ->call('removeLine', 0)
        ->assertCount('lines', 1);
});

it('sends a draft to the supplier', function (): void {
    $orders = app(SavePurchaseOrder::class);

    $draft = $orders->handle(null, $this->outlet, $this->keeper, [
        ['inventory_item_id' => $this->beans->id, 'quantity' => 10, 'unit_cost' => 120000],
    ], ['supplier_id' => $this->supplier->id]);

    Livewire::test('pages::purchases.show', ['purchaseOrder' => $draft])
        ->call('place')
        ->assertSee(PurchaseOrderStatus::Ordered->label());

    expect($draft->refresh()->status)->toBe(PurchaseOrderStatus::Ordered);
});

it('books a part delivery into stock from the screen', function (): void {
    $order = placedOrder($this);
    $line = $order->items->first();

    Livewire::test('pages::purchases.show', ['purchaseOrder' => $order])
        ->set("arrivals.{$line->id}", '4')
        ->call('receive')
        ->assertSee(PurchaseOrderStatus::PartiallyReceived->label());

    expect((float) app(MoveStock::class)->level($this->outlet, $this->beans)->quantity)->toBe(4.0)
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived);
});

it('fills every line with what is still owed', function (): void {
    $order = placedOrder($this);
    $line = $order->items->first();

    Livewire::test('pages::purchases.show', ['purchaseOrder' => $order])
        ->call('fillOutstanding')
        ->assertSet("arrivals.{$line->id}", '10');
});

it('closes the order when the whole delivery lands', function (): void {
    $order = placedOrder($this);

    Livewire::test('pages::purchases.show', ['purchaseOrder' => $order])
        ->call('fillOutstanding')
        ->call('receive')
        ->assertSee(PurchaseOrderStatus::Received->label());

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($this->beans->refresh()->average_cost)->toBe(120000);
});

it('refuses to open an order belonging to another outlet', function (): void {
    $order = placedOrder($this);

    $other = Outlet::factory()->create();
    $this->keeper->outlets()->attach($other, ['role' => Role::Warehouse->value]);

    $this->withSession([CurrentOutlet::SESSION_KEY => $other->getKey()])
        ->get(route('purchases.show', $order))
        ->assertNotFound();
});
