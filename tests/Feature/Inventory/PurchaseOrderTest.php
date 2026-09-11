<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\MoveStock;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\ReceiveGoods;
use App\Domain\Purchasing\SavePurchaseOrder;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->buyer = User::factory()->atOutlet($this->outlet, Role::Manager)->create();
    $this->supplier = Supplier::factory()->create();

    $this->beans = InventoryItem::factory()->create(['name' => 'Biji Kopi', 'average_cost' => 0]);
    $this->milk = InventoryItem::factory()->create(['name' => 'Susu Segar', 'average_cost' => 0]);

    $this->save = app(SavePurchaseOrder::class);
    $this->receive = app(ReceiveGoods::class);
    $this->stock = app(MoveStock::class);
});

/** A two-line draft order for the outlet under test. */
function draft(object $test): PurchaseOrder
{
    return $test->save->handle(null, $test->outlet, $test->buyer, [
        ['inventory_item_id' => $test->beans->id, 'quantity' => 10, 'unit_cost' => 120000],
        ['inventory_item_id' => $test->milk->id, 'quantity' => 24, 'unit_cost' => 18000],
    ], ['supplier_id' => $test->supplier->id]);
}

/** The line for the given item on the given order. */
function lineFor(PurchaseOrder $order, InventoryItem $item): string
{
    return $order->items->firstWhere('inventory_item_id', $item->getKey())->id;
}

it('totals a draft from its lines', function (): void {
    $order = draft($this);

    expect($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->subtotal)->toBe(1632000)
        ->and($order->grand_total)->toBe(1632000)
        ->and($order->items)->toHaveCount(2);
});

it('snapshots the item name onto the line', function (): void {
    $order = draft($this);

    $this->beans->update(['name' => 'Biji Kopi Arabika']);

    expect($order->items->firstWhere('inventory_item_id', $this->beans->id)->item_name)->toBe('Biji Kopi');
});

it('numbers orders in sequence per outlet', function (): void {
    $first = draft($this);
    $second = draft($this);

    expect($first->number)->toStartWith('PO-'.$this->outlet->code.'-')
        ->and($first->number)->toEndWith('0001')
        ->and($second->number)->toEndWith('0002');
});

it('moves goods into stock when a delivery arrives', function (): void {
    $order = $this->save->place(draft($this));

    $received = $this->receive->handle($order, $this->buyer, [
        lineFor($order, $this->beans) => 10,
        lineFor($order, $this->milk) => 24,
    ]);

    expect($received->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $this->stock->level($this->outlet, $this->beans)->quantity)->toBe(10.0)
        ->and($this->beans->refresh()->average_cost)->toBe(120000);
});

it('holds an order open when only part of it arrives', function (): void {
    $order = $this->save->place(draft($this));
    $line = lineFor($order, $this->beans);

    $received = $this->receive->handle($order, $this->buyer, [$line => 4]);

    expect($received->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and((float) $received->items->firstWhere('id', $line)->quantity_received)->toBe(4.0)
        ->and($received->received_at)->toBeNull();
});

it('closes the order once the rest of the delivery lands', function (): void {
    $order = $this->save->place(draft($this));
    $beans = lineFor($order, $this->beans);
    $milk = lineFor($order, $this->milk);

    $this->receive->handle($order, $this->buyer, [$beans => 4, $milk => 24]);
    $received = $this->receive->handle($order->refresh(), $this->buyer, [$beans => 6]);

    expect($received->status)->toBe(PurchaseOrderStatus::Received)
        ->and((float) $this->stock->level($this->outlet, $this->beans)->quantity)->toBe(10.0)
        ->and($received->received_at)->not->toBeNull();
});

it('caps a delivery at what was ordered', function (): void {
    $order = $this->save->place(draft($this));
    $line = lineFor($order, $this->beans);

    $received = $this->receive->handle($order, $this->buyer, [$line => 50]);

    // A supplier who sends extra needs a new line, not a silent rewrite.
    expect((float) $received->items->firstWhere('id', $line)->quantity_received)->toBe(10.0)
        ->and((float) $this->stock->level($this->outlet, $this->beans)->quantity)->toBe(10.0);
});

it('points the movement back at the order that caused it', function (): void {
    $order = $this->save->place(draft($this));

    $this->receive->handle($order, $this->buyer, [lineFor($order, $this->beans) => 10]);

    $movement = StockMovement::query()->where('inventory_item_id', $this->beans->id)->firstOrFail();

    expect($movement->type)->toBe(StockMovementType::Purchase)
        ->and($movement->source_id)->toBe($order->getKey())
        ->and($movement->unit_cost)->toBe(120000);
});

it('refuses a delivery against a draft', function (): void {
    $order = draft($this);

    $this->receive->handle($order, $this->buyer, [lineFor($order, $this->beans) => 1]);
})->throws(HttpException::class);

it('refuses a delivery with nothing filled in', function (): void {
    $this->receive->handle($this->save->place(draft($this)), $this->buyer, []);
})->throws(HttpException::class);

it('refuses to edit an order that is already received', function (): void {
    $order = $this->save->place(draft($this));

    $this->receive->handle($order, $this->buyer, [
        lineFor($order, $this->beans) => 10,
        lineFor($order, $this->milk) => 24,
    ]);

    $this->save->handle($order->refresh(), $this->outlet, $this->buyer, [
        ['inventory_item_id' => $this->beans->id, 'quantity' => 99, 'unit_cost' => 1],
    ], ['supplier_id' => $this->supplier->id]);
})->throws(HttpException::class);

it('refuses to cancel an order that already had a delivery', function (): void {
    $order = $this->save->place(draft($this));

    $this->receive->handle($order, $this->buyer, [lineFor($order, $this->beans) => 1]);

    $this->save->cancel($order->refresh());
})->throws(HttpException::class);

it('cancels an untouched order', function (): void {
    expect($this->save->cancel(draft($this))->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('refuses to place an order with no lines', function (): void {
    $order = $this->save->handle(null, $this->outlet, $this->buyer, [], ['supplier_id' => $this->supplier->id]);

    $this->save->place($order);
})->throws(HttpException::class);

it('drops a line the buyer removed from a draft', function (): void {
    $order = draft($this);

    $edited = $this->save->handle($order, $this->outlet, $this->buyer, [
        ['inventory_item_id' => $this->beans->id, 'quantity' => 10, 'unit_cost' => 120000],
    ], ['supplier_id' => $this->supplier->id]);

    expect($edited->items)->toHaveCount(1)
        ->and($edited->subtotal)->toBe(1200000);
});
