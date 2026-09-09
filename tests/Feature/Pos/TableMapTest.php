<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\DiningTable;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\TableMap;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->map = app(TableMap::class);
});

it('hangs the open bill on the table it belongs to', function (): void {
    $busy = DiningTable::factory()->for($this->outlet)->create(['name' => 'M1']);
    DiningTable::factory()->for($this->outlet)->create(['name' => 'M2']);

    Order::factory()->for($this->outlet)->create([
        'dining_table_id' => $busy->id,
        'status' => OrderStatus::Open,
        'grand_total' => 55000,
    ]);

    $tables = $this->map->forOutlet($this->outlet)->keyBy('name');

    expect($tables['M1']->open_order?->grand_total)->toBe(55000)
        ->and($tables['M2']->open_order)->toBeNull();
});

it('moves a running bill to another table', function (): void {
    $from = DiningTable::factory()->for($this->outlet)->create();
    $to = DiningTable::factory()->for($this->outlet)->create();

    $order = Order::factory()->for($this->outlet)->create([
        'dining_table_id' => $from->id,
        'status' => OrderStatus::Open,
    ]);

    $this->map->move($order, $to);

    expect($order->fresh()->dining_table_id)->toBe($to->id);
});

it('refuses to move a bill that is already closed', function (): void {
    $table = DiningTable::factory()->for($this->outlet)->create();

    $order = Order::factory()->for($this->outlet)->create(['status' => OrderStatus::Paid]);

    expect(fn () => $this->map->move($order, $table))
        ->toThrow(HttpException::class);
});

it('will not move a bill onto a table in another outlet', function (): void {
    $elsewhere = DiningTable::factory()->for(Outlet::factory())->create();

    $order = Order::factory()->for($this->outlet)->create(['status' => OrderStatus::Open]);

    expect(fn () => $this->map->move($order, $elsewhere))
        ->toThrow(HttpException::class);
});

it('keeps the merged bill on record instead of deleting it', function (): void {
    $source = Order::factory()->for($this->outlet)->create([
        'status' => OrderStatus::Open,
        'dining_table_id' => DiningTable::factory()->for($this->outlet)->create()->id,
    ]);

    $target = Order::factory()->for($this->outlet)->create(['status' => OrderStatus::Open]);

    $this->map->merge($source, $target);

    $source->refresh();

    expect($source->status)->toBe(OrderStatus::Voided)
        ->and($source->merged_into_order_id)->toBe($target->id)
        ->and($source->dining_table_id)->toBeNull()
        ->and($source->grand_total)->toBe(0)
        ->and(Order::count())->toBe(2);
});

it('refuses to merge a bill into itself', function (): void {
    $order = Order::factory()->for($this->outlet)->create(['status' => OrderStatus::Open]);

    expect(fn () => $this->map->merge($order, $order))
        ->toThrow(HttpException::class);
});
