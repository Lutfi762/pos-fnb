<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\AdvanceTicket;
use App\Domain\Kitchen\Enums\Station;
use App\Domain\Kitchen\Enums\TicketColumn;
use App\Domain\Kitchen\Events\BoardChanged;
use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->cook = User::factory()->atOutlet($this->outlet, Role::Kitchen)->create();

    $this->actingAs($this->cook);
    app(CurrentOutlet::class)->set($this->outlet);
});

/**
 * A bill with one line per station, already sent to the pass.
 */
function ticketed(Outlet $outlet, User $cook, string $station = 'kitchen', int $prep = 10): Order
{
    $order = Order::factory()->for($outlet)->create(['user_id' => $cook->getKey()]);

    OrderItem::factory()->for($order)->create([
        'station' => $station,
        'prep_minutes' => $prep,
        'status' => OrderItemStatus::Sent,
        'sent_at' => now(),
    ]);

    return $order;
}

it('groups a bill into one ticket per station', function (): void {
    $order = ticketed($this->outlet, $this->cook);

    OrderItem::factory()->for($order)->create([
        'station' => 'bar',
        'status' => OrderItemStatus::Sent,
        'sent_at' => now(),
    ]);

    $tickets = app(KitchenBoard::class)->tickets($this->outlet);

    expect($tickets)->toHaveCount(2)
        ->and($tickets->pluck('station')->all())
        ->toEqualCanonicalizing([Station::Kitchen, Station::Bar]);
});

it('shows only the station the screen is filtered to', function (): void {
    ticketed($this->outlet, $this->cook, 'bar');

    expect(app(KitchenBoard::class)->tickets($this->outlet, Station::Kitchen))->toBeEmpty()
        ->and(app(KitchenBoard::class)->tickets($this->outlet, Station::Bar))->toHaveCount(1);
});

it('keeps a ticket in the least advanced column its lines sit in', function (): void {
    $order = ticketed($this->outlet, $this->cook);

    OrderItem::factory()->for($order)->create([
        'station' => 'kitchen',
        'status' => OrderItemStatus::Ready,
        'sent_at' => now(),
    ]);

    expect(app(KitchenBoard::class)->tickets($this->outlet)->first()->column)
        ->toBe(TicketColumn::New);
});

it('times the ticket by its slowest line', function (): void {
    $order = ticketed($this->outlet, $this->cook, 'kitchen', prep: 5);

    OrderItem::factory()->for($order)->create([
        'station' => 'kitchen',
        'prep_minutes' => 20,
        'status' => OrderItemStatus::Sent,
        'sent_at' => now(),
    ]);

    expect(app(KitchenBoard::class)->tickets($this->outlet)->first()->targetSeconds)
        ->toBe(1200);
});

it('leaves another outlet board alone', function (): void {
    $elsewhere = Outlet::factory()->create();
    ticketed($elsewhere, $this->cook);

    expect(app(KitchenBoard::class)->tickets($this->outlet))->toBeEmpty();
});

it('moves a ticket along and stamps when it was started', function (): void {
    $order = ticketed($this->outlet, $this->cook);

    app(AdvanceTicket::class)->advance($order, Station::Kitchen, TicketColumn::Preparing);

    $item = $order->items()->first();

    expect($item->status)->toBe(OrderItemStatus::Preparing)
        ->and($item->started_at)->not->toBeNull();
});

it('keeps the first start time when a ticket is tapped back and forward', function (): void {
    $order = ticketed($this->outlet, $this->cook);
    $advance = app(AdvanceTicket::class);

    $advance->advance($order, Station::Kitchen, TicketColumn::Preparing);
    $first = $order->items()->first()->started_at;

    $this->travel(2)->minutes();
    $advance->rewind($order, Station::Kitchen, TicketColumn::Preparing);
    $advance->advance($order, Station::Kitchen, TicketColumn::New);

    expect($order->items()->first()->started_at->timestamp)->toBe($first->timestamp);
});

it('tells every other screen when the board moves', function (): void {
    Event::fake([BoardChanged::class]);

    $order = ticketed($this->outlet, $this->cook);
    app(AdvanceTicket::class)->advance($order, Station::Kitchen, TicketColumn::Preparing);

    Event::assertDispatched(BoardChanged::class, fn (BoardChanged $event): bool => $event->outletId === $this->outlet->getKey());
});

it('opens the board and advances a ticket from the screen', function (): void {
    $order = ticketed($this->outlet, $this->cook);

    Livewire::test('pages::kitchen.index')
        ->assertSee($order->number)
        ->call('advance', $order->getKey(), 'kitchen', 'new');

    expect($order->items()->first()->status)->toBe(OrderItemStatus::Preparing);
});

it('refuses to touch a ticket from another outlet', function (): void {
    $order = ticketed(Outlet::factory()->create(), $this->cook);

    Livewire::test('pages::kitchen.index')
        ->call('advance', $order->getKey(), 'kitchen', 'new');
})->throws(ModelNotFoundException::class);
