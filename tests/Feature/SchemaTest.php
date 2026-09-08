<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

it('gives every domain row a ULID primary key', function (): void {
    $outlet = Outlet::factory()->create();

    expect($outlet->id)->toBeString()
        ->and(Str::isUlid($outlet->id))->toBeTrue();
});

it('assigns a user to an outlet with a role', function (): void {
    $outlet = Outlet::factory()->create();
    $user = User::factory()->create();

    $user->outlets()->attach($outlet, ['role' => Role::Cashier->value]);

    expect($user->load('outlets')->roleAt($outlet))->toBe(Role::Cashier);
});

it('lets an owner reach an outlet they are not assigned to', function (): void {
    $home = Outlet::factory()->create();
    $elsewhere = Outlet::factory()->create();

    $owner = User::factory()->create();
    $owner->outlets()->attach($home, ['role' => Role::Owner->value]);

    $cashier = User::factory()->create();
    $cashier->outlets()->attach($home, ['role' => Role::Cashier->value]);

    expect($owner->load('outlets')->canAccessOutlet($elsewhere))->toBeTrue()
        ->and($cashier->load('outlets')->canAccessOutlet($elsewhere))->toBeFalse()
        ->and($cashier->canAccessOutlet($home))->toBeTrue();
});

it('keeps order numbers unique per outlet but reusable across outlets', function (): void {
    $first = Outlet::factory()->create();
    $second = Outlet::factory()->create();

    Order::factory()->create(['outlet_id' => $first->id, 'number' => 'ORD-0001']);
    Order::factory()->create(['outlet_id' => $second->id, 'number' => 'ORD-0001']);

    expect(Order::where('number', 'ORD-0001')->count())->toBe(2);

    expect(fn () => Order::factory()->create([
        'outlet_id' => $first->id,
        'number' => 'ORD-0001',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('reports what an order still owes and never a negative balance', function (): void {
    $order = Order::factory()->create(['grand_total' => 50_000, 'paid_total' => 20_000]);
    expect($order->outstanding())->toBe(30_000);

    $overpaid = Order::factory()->create(['grand_total' => 50_000, 'paid_total' => 100_000]);
    expect($overpaid->outstanding())->toBe(0);
});
