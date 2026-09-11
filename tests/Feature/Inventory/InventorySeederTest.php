<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\MoveStock;
use App\Domain\Purchasing\Models\Supplier;
use Database\Seeders\InventorySeeder;
use Database\Seeders\OutletSeeder;
use Database\Seeders\UserSeeder;

beforeEach(function (): void {
    $this->seed(OutletSeeder::class);
    $this->seed(UserSeeder::class);
    $this->seed(InventorySeeder::class);

    // The seeder walks outlets by code, so Bintaro is the one that opens full.
    $this->outlet = Outlet::where('code', 'BTR')->firstOrFail();
});

it('stocks every outlet with ingredients and suppliers', function (): void {
    expect(InventoryItem::count())->toBe(18)
        ->and(Supplier::count())->toBe(4);
});

it('opens a balance at each outlet through the ledger', function (): void {
    $beans = InventoryItem::where('sku', 'BJ-ARB')->firstOrFail();
    $stock = app(MoveStock::class);

    expect((float) $stock->level($this->outlet, $beans)->quantity)->toBe(12.0)
        ->and($beans->average_cost)->toBe(185000)
        ->and(StockMovement::where('inventory_item_id', $beans->id)->count())->toBe(3);
});

it('leaves something on the restock list', function (): void {
    $straws = InventoryItem::where('sku', 'KM-SDT')->firstOrFail();

    // 250 on hand against a reorder point of 300.
    expect(app(MoveStock::class)->level($this->outlet, $straws)->needsRestock())->toBeTrue();
});

it('does not double the stock when seeded twice', function (): void {
    $beans = InventoryItem::where('sku', 'BJ-ARB')->firstOrFail();

    $this->seed(InventorySeeder::class);

    expect((float) app(MoveStock::class)->level($this->outlet, $beans)->quantity)->toBe(12.0)
        ->and(InventoryItem::count())->toBe(18)
        ->and(Supplier::count())->toBe(4);
});
