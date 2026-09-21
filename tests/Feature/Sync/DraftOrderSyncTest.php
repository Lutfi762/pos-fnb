<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->variant = ProductVariant::factory()->for(Product::factory()->for(Category::factory()))->create(['base_price' => 20_000]);

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

function draftPayload(ProductVariant $variant, ?string $orderKey = null, ?int $revision = null): array
{
    return [
        'operation_key' => (string) Str::ulid(),
        'client_order_key' => $orderKey ?? (string) Str::ulid(),
        'revision' => $revision,
        'type' => 'take_away',
        'guest_count' => 1,
        'customer_name' => 'Ayu',
        'lines' => [['variant_id' => $variant->getKey(), 'quantity' => 2, 'note' => null, 'unit_price' => 1]],
    ];
}

it('rebuilds a synced draft price from the server catalog', function (): void {
    $this->postJson(route('sync.draft-orders.store'), draftPayload($this->variant))
        ->assertOk()
        ->assertJsonPath('status', 'applied');

    expect(Order::query()->sole()->items()->sole()->unit_price)->toBe(20_000);
});

it('applies a replayed operation once', function (): void {
    $payload = draftPayload($this->variant);

    $this->postJson(route('sync.draft-orders.store'), $payload)->assertOk();
    $this->postJson(route('sync.draft-orders.store'), $payload)->assertOk();

    expect(Order::query()->count())->toBe(1)
        ->and(SyncOperation::query()->count())->toBe(1);
});

it('returns a conflict for an old draft revision', function (): void {
    $payload = draftPayload($this->variant);
    $created = $this->postJson(route('sync.draft-orders.store'), $payload)->assertOk();

    $this->postJson(route('sync.draft-orders.store'), draftPayload($this->variant, $payload['client_order_key'], 1))
        ->assertConflict()
        ->assertJsonPath('status', 'conflict')
        ->assertJsonPath('revision', $created->json('revision'));
});
