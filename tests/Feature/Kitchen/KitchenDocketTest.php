<?php

declare(strict_types=1);

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\AdvanceTicket;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Models\User;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['has_kitchen_display' => false]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

it('prints one docket per station', function (): void {
    $order = Order::factory()->for($this->outlet)->create(['user_id' => $this->cashier->getKey()]);

    OrderItem::factory()->for($order)->sent()->create([
        'station' => 'kitchen',
        'product_name' => 'Nasi Goreng Kampung',
        'note' => 'tanpa cabai',
    ]);

    OrderItem::factory()->for($order)->sent()->create([
        'station' => 'bar',
        'product_name' => 'Es Teh Manis',
    ]);

    $this->get(route('kitchen.docket', $order))
        ->assertOk()
        ->assertSee('Nasi Goreng Kampung')
        ->assertSee('Es Teh Manis')
        ->assertSee('tanpa cabai');
});

it('keeps a docket from another outlet out of reach', function (): void {
    $order = Order::factory()->for(Outlet::factory())->create(['user_id' => $this->cashier->getKey()]);

    $this->get(route('kitchen.docket', $order))->assertForbidden();
});

it('leaves already sent lines on their original clock', function (): void {
    $order = Order::factory()->for($this->outlet)->create(['user_id' => $this->cashier->getKey()]);

    $old = OrderItem::factory()->for($order)->sent()->create(['sent_at' => now()->subMinutes(20)]);
    OrderItem::factory()->for($order)->create(['status' => OrderItemStatus::Pending]);

    expect(app(AdvanceTicket::class)->send($order))->toBe(1)
        ->and($old->refresh()->sent_at->timestamp)->toBe($old->sent_at->timestamp);
});
