<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Modifier;
use App\Domain\Catalog\Models\ModifierGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->variant = ProductVariant::factory()->for(Product::factory()->for(Category::factory()))->create(['base_price' => 20_000]);

    $this->actingAs($this->cashier);
    $this->device = Device::query()->create([
        'outlet_id' => $this->outlet->getKey(),
        'name' => 'Test terminal',
        'fingerprint' => (string) Str::ulid(),
        'last_seen_at' => now(),
    ]);
    app()->instance(DeviceRegistry::class, Mockery::mock(DeviceRegistry::class, function ($mock): void {
        $mock->shouldReceive('current')->andReturn($this->device);
    }));
    app(CurrentOutlet::class)->set($this->outlet);
});

function draftPayload(ProductVariant $variant, ?string $orderKey = null, ?int $revision = null): array
{
    return [
        'operation_key' => (string) Str::ulid(),
        'sequence' => 1,
        'client_created_at' => now()->toISOString(),
        'client_order_key' => $orderKey ?? (string) Str::ulid(),
        'revision' => $revision,
        'type' => 'take_away',
        'guest_count' => 1,
        'customer_name' => 'Ayu',
        'lines' => [['variant_id' => $variant->getKey(), 'modifier_ids' => [], 'quantity' => 2, 'note' => null, 'unit_price' => 1]],
    ];
}

function syncDraft(object $test, array $payload): TestResponse
{
    return $test->postJson(route('sync.draft-orders.store'), $payload);
}

it('rebuilds a synced draft price from the server catalog', function (): void {
    syncDraft($this, draftPayload($this->variant))
        ->assertOk()
        ->assertJsonPath('status', 'applied');

    expect(Order::query()->sole()->items()->sole()->unit_price)->toBe(20_000);
});

it('rebuilds modifier snapshots from the server catalog', function (): void {
    $group = ModifierGroup::factory()->create(['min_select' => 1, 'max_select' => 1]);
    $this->variant->product->modifierGroups()->attach($group);
    $modifier = Modifier::factory()->for($group, 'group')->create(['price' => 3_000]);
    $payload = draftPayload($this->variant);
    $payload['lines'][0]['modifier_ids'] = [$modifier->getKey()];

    syncDraft($this, $payload)->assertOk();

    expect(Order::query()->sole()->items()->sole()->modifiers()->sole()->price)->toBe(3_000);
});

it('refuses a modifier that is not attached to the product', function (): void {
    $payload = draftPayload($this->variant);
    $payload['lines'][0]['modifier_ids'] = [Modifier::factory()->create()->getKey()];

    syncDraft($this, $payload)->assertUnprocessable();
});

it('applies a replayed operation once', function (): void {
    $payload = draftPayload($this->variant);

    syncDraft($this, $payload)->assertOk();
    syncDraft($this, $payload)->assertOk();

    expect(Order::query()->count())->toBe(1)
        ->and(SyncOperation::query()->count())->toBe(1);
});

it('returns a conflict for an old draft revision', function (): void {
    $payload = draftPayload($this->variant);
    $created = syncDraft($this, $payload)->assertOk();

    $conflict = draftPayload($this->variant, $payload['client_order_key'], $created->json('revision') + 1);
    $conflict['sequence'] = 2;

    syncDraft($this, $conflict)
        ->assertConflict()
        ->assertJsonPath('status', 'conflict')
        ->assertJsonPath('revision', $created->json('revision'));

    expect(SyncOperation::query()->where('sequence', 2)->sole()->status->value)->toBe('conflict');
});

it('refuses a device sequence gap', function (): void {
    $payload = draftPayload($this->variant);
    $payload['sequence'] = 2;

    syncDraft($this, $payload)
        ->assertConflict();

    expect(Order::query()->count())->toBe(0);
});

it('blocks a later device operation after a conflict', function (): void {
    $first = draftPayload($this->variant);
    syncDraft($this, $first)->assertOk();

    $conflict = draftPayload($this->variant, $first['client_order_key'], 999);
    $conflict['sequence'] = 2;
    syncDraft($this, $conflict)->assertConflict();

    $later = draftPayload($this->variant);
    $later['sequence'] = 3;
    syncDraft($this, $later)->assertConflict();
});
