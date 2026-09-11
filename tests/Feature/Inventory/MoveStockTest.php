<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\MoveStock;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->keeper = User::factory()->atOutlet($this->outlet, Role::Warehouse)->create();
    $this->item = InventoryItem::factory()->create(['average_cost' => 0]);
    $this->stock = app(MoveStock::class);
});

it('adds a delivery to the balance', function (): void {
    $movement = $this->stock->record(
        $this->outlet,
        $this->item,
        StockMovementType::Purchase,
        12.5,
        $this->keeper,
        unitCost: 15000,
    );

    expect((float) $movement->balance_after)->toBe(12.5)
        ->and((float) $this->stock->level($this->outlet, $this->item)->quantity)->toBe(12.5);
});

it('forces waste to reduce stock even when typed as positive', function (): void {
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 10, $this->keeper);

    $movement = $this->stock->record($this->outlet, $this->item, StockMovementType::Waste, 3, $this->keeper);

    expect((float) $movement->quantity)->toBe(-3.0)
        ->and((float) $movement->balance_after)->toBe(7.0);
});

it('lets an adjustment go either way', function (): void {
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 10, $this->keeper);

    $movement = $this->stock->record($this->outlet, $this->item, StockMovementType::Adjustment, -4, $this->keeper);

    expect((float) $movement->balance_after)->toBe(6.0);
});

it('refuses a movement of nothing', function (): void {
    $this->stock->record($this->outlet, $this->item, StockMovementType::Adjustment, 0, $this->keeper);
})->throws(HttpException::class);

it('averages the cost across deliveries at different prices', function (): void {
    // 10 at 10.000 then 10 at 20.000 sits at 15.000 a unit.
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 10, $this->keeper, unitCost: 10000);
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 10, $this->keeper, unitCost: 20000);

    expect($this->item->refresh()->average_cost)->toBe(15000);
});

it('keeps the cost of stock already on the shelf', function (): void {
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 30, $this->keeper, unitCost: 10000);
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 10, $this->keeper, unitCost: 20000);

    // Weighted, not the latest price: (30*10.000 + 10*20.000) / 40.
    expect($this->item->refresh()->average_cost)->toBe(12500);
});

it('corrects the books to the shelf on a count', function (): void {
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 20, $this->keeper);

    $movement = $this->stock->count($this->outlet, $this->item, 18.25, $this->keeper);

    expect($movement)->not->toBeNull()
        ->and((float) $movement->quantity)->toBe(-1.75)
        ->and($movement->type)->toBe(StockMovementType::Count)
        ->and((float) $movement->balance_after)->toBe(18.25);
});

it('writes nothing when a count agrees with the books', function (): void {
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 20, $this->keeper);

    $movement = $this->stock->count($this->outlet, $this->item, 20, $this->keeper);

    expect($movement)->toBeNull()
        ->and($this->stock->level($this->outlet, $this->item)->counted_at)->not->toBeNull();
});

it('keeps each outlet on its own balance', function (): void {
    $other = Outlet::factory()->create();

    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 10, $this->keeper);
    $this->stock->record($other, $this->item, StockMovementType::Purchase, 4, $this->keeper);

    expect((float) $this->stock->level($this->outlet, $this->item)->quantity)->toBe(10.0)
        ->and((float) $this->stock->level($other, $this->item)->quantity)->toBe(4.0);
});

it('flags an item at or under its reorder point', function (): void {
    $this->stock->setReorderPoint($this->outlet, $this->item, 5);
    $this->stock->record($this->outlet, $this->item, StockMovementType::Purchase, 5, $this->keeper);

    expect($this->stock->level($this->outlet, $this->item)->needsRestock())->toBeTrue();
});
