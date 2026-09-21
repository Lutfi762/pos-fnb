<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\SaveOrder;
use App\Models\User;

it('increments an order revision after a cart write', function (): void {
    $outlet = Outlet::factory()->create();
    $cashier = User::factory()->create();
    $order = Order::factory()->for($outlet)->for($cashier, 'cashier')->create(['revision' => 1]);

    app(SaveOrder::class)->handle($order, $outlet, $cashier, [], []);

    expect($order->refresh()->revision)->toBe(2);
});
