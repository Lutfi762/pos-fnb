<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\MoveStock;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->keeper = User::factory()->atOutlet($this->outlet, Role::Warehouse)->create();
    $this->item = InventoryItem::factory()->create(['name' => 'Susu Segar', 'average_cost' => 0]);

    $this->actingAs($this->keeper);
    app(CurrentOutlet::class)->set($this->outlet);
});

it('lets warehouse staff open the stock screen', function (): void {
    $this->get(route('stock.index'))
        ->assertOk()
        ->assertSee('Susu Segar');
});

it('keeps a cashier out of the stock screen', function (): void {
    $cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($cashier)->get(route('stock.index'))->assertForbidden();
});

it('adjusts a balance from the sheet', function (): void {
    Livewire::test('pages::stock.index')
        ->call('edit', $this->item->getKey(), 'adjust')
        ->set('quantity', '7,5')
        ->call('save');

    expect((float) app(MoveStock::class)->level($this->outlet, $this->item)->quantity)->toBe(7.5);
});

it('writes a count as the difference against the shelf', function (): void {
    app(MoveStock::class)->record(
        $this->outlet,
        $this->item,
        StockMovementType::Purchase,
        10,
        $this->keeper,
        unitCost: 12000,
    );

    Livewire::test('pages::stock.index')
        ->call('edit', $this->item->getKey(), 'count')
        ->set('quantity', '8')
        ->call('save');

    expect((float) app(MoveStock::class)->level($this->outlet, $this->item)->quantity)->toBe(8.0);
});

it('filters down to what is running low', function (): void {
    $stock = app(MoveStock::class);
    $stock->record($this->outlet, $this->item, StockMovementType::Purchase, 100, $this->keeper);
    $stock->setReorderPoint($this->outlet, $this->item, 5);

    $thin = InventoryItem::factory()->create(['name' => 'Gula Aren']);
    $stock->record($this->outlet, $thin, StockMovementType::Purchase, 1, $this->keeper);
    $stock->setReorderPoint($this->outlet, $thin, 10);

    Livewire::test('pages::stock.index')
        ->set('lowOnly', true)
        ->assertSee('Gula Aren')
        ->assertDontSee('Susu Segar');
});

it('finds an item by name', function (): void {
    InventoryItem::factory()->create(['name' => 'Gula Aren']);

    Livewire::test('pages::stock.index')
        ->set('search', 'Susu')
        ->assertSee('Susu Segar')
        ->assertDontSee('Gula Aren');
});
