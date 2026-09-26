<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\RecipeItem;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\MoveStock;
use App\Domain\Inventory\StockReview;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Domain\Sync\ResolveOperation;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['code' => 'KMG', 'tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create(['pin_hash' => '654321']);
    $this->variant = ProductVariant::factory()
        ->for(Product::factory()->for(Category::factory()))
        ->create(['base_price' => 25_000]);
    $this->cash = PaymentMethod::create([
        'name' => 'Tunai', 'type' => 'cash', 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0,
    ]);

    $this->device = offlineDevice($this->outlet);
    $this->sequence = 0;

    app()->instance(DeviceRegistry::class, Mockery::mock(DeviceRegistry::class, function ($mock): void {
        $mock->shouldReceive('current')->andReturnUsing(fn () => $this->device);
    }));

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

function offlineDevice(Outlet $outlet): Device
{
    return Device::query()->create([
        'outlet_id' => $outlet->getKey(),
        'name' => 'Tablet',
        'fingerprint' => (string) Str::ulid(),
        'last_seen_at' => now(),
    ]);
}

/**
 * One queued command, as the browser's outbox sends it.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function command(object $test, string $command, array $payload, ?string $at = null): array
{
    return [
        'command' => $command,
        'operation_key' => (string) Str::ulid(),
        'sequence' => ++$test->sequence,
        'client_created_at' => $at ?? now()->toISOString(),
        ...$payload,
    ];
}

function saleCommands(object $test, int $quantity = 1, array $extra = []): array
{
    $key = (string) Str::ulid();

    return [
        command($test, 'order.saved', [
            'client_order_key' => $key,
            'revision' => null,
            'type' => 'take_away',
            'guest_count' => 1,
            'customer_name' => null,
            'kitchen_handled' => true,
            'lines' => [['variant_id' => $test->variant->getKey(), 'modifier_ids' => [], 'quantity' => $quantity, 'note' => null]],
            ...$extra,
        ]),
        command($test, 'payment.recorded', [
            'client_order_key' => $key,
            'payment_method_id' => $test->cash->getKey(),
            'amount' => 25_000 * $quantity,
            'tendered' => 50_000 * $quantity,
        ]),
    ];
}

function send(object $test, array $command): TestResponse
{
    return $test->postJson(route('sync.operations.store'), $command);
}

it('applies fifteen offline cash sales exactly once, even when the queue is replayed', function (): void {
    $commands = [];

    foreach (range(1, 15) as $sale) {
        array_push($commands, ...saleCommands($this));
    }

    // The tablet sends the first twelve, loses the responses, reloads, and
    // sends everything again from the top.
    foreach (array_slice($commands, 0, 12) as $each) {
        send($this, $each)->assertOk();
    }

    foreach ($commands as $each) {
        send($this, $each)->assertOk()->assertJsonPath('status', 'applied');
    }

    expect(Order::query()->where('status', OrderStatus::Paid)->count())->toBe(15)
        ->and(Payment::query()->count())->toBe(15)
        ->and(SyncOperation::query()->where('status', SyncStatus::Applied)->count())->toBe(30);
});

it('records an offline sale at the moment it happened, not when it synced', function (): void {
    $at = now()->subHours(3)->startOfMinute();
    [$order, $payment] = saleCommands($this);
    $order['client_created_at'] = $at->toISOString();
    $payment['client_created_at'] = $at->toISOString();

    send($this, $order)->assertOk();
    send($this, $payment)->assertOk();

    $synced = Order::query()->sole();

    expect($synced->placed_at->equalTo($at))->toBeTrue()
        ->and($synced->paid_at->equalTo($at))->toBeTrue()
        ->and($synced->payments()->sole()->paid_at->equalTo($at))->toBeTrue();
});

it('keeps the bill number the offline till printed', function (): void {
    $number = 'KMG-'.strtoupper(substr((string) $this->device->getKey(), -4)).'-'.now()->format('ymd').'-5001';
    [$order] = saleCommands($this, extra: ['number' => $number]);

    send($this, $order)->assertOk()->assertJsonPath('number', $number);

    expect(Order::query()->sole()->number)->toBe($number);
});

it('issues its own number when the offline one belongs to another till', function (): void {
    [$order] = saleCommands($this, extra: ['number' => 'KMG-ZZZZ-'.now()->format('ymd').'-5001']);

    send($this, $order)->assertOk();

    expect(Order::query()->sole()->number)->not->toBe('KMG-ZZZZ-'.now()->format('ymd').'-5001');
});

it('marks offline lines as already served so the kitchen board stays clear', function (): void {
    [$order] = saleCommands($this);

    send($this, $order)->assertOk();

    expect(Order::query()->sole()->items()->sole()->status)->toBe(OrderItemStatus::Served);
});

it('takes the recipe off the shelf when a sale is paid, and only once', function (): void {
    $beans = InventoryItem::factory()->create(['average_cost' => 300]);
    RecipeItem::create(['product_variant_id' => $this->variant->getKey(), 'inventory_item_id' => $beans->getKey(), 'quantity' => 18]);
    app(MoveStock::class)->record($this->outlet, $beans, StockMovementType::Purchase, 1000, unitCost: 300);

    [$order, $payment] = saleCommands($this, quantity: 2);

    send($this, $order)->assertOk();
    send($this, $payment)->assertOk();
    send($this, $payment)->assertOk();

    expect((float) StockLevel::query()->sole()->quantity)->toBe(964.0)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(1);
});

it('accepts two tills selling the last beans offline and lists the item for a count', function (): void {
    $beans = InventoryItem::factory()->create(['average_cost' => 300]);
    RecipeItem::create(['product_variant_id' => $this->variant->getKey(), 'inventory_item_id' => $beans->getKey(), 'quantity' => 18]);
    app(MoveStock::class)->record($this->outlet, $beans, StockMovementType::Purchase, 18, unitCost: 300);

    foreach (saleCommands($this) as $each) {
        send($this, $each)->assertOk();
    }

    // The second tablet has its own device row and its own numbering.
    $this->device = offlineDevice($this->outlet);
    $this->sequence = 0;

    foreach (saleCommands($this) as $each) {
        send($this, $each)->assertOk()->assertJsonPath('status', 'applied');
    }

    expect(Order::query()->where('status', OrderStatus::Paid)->count())->toBe(2)
        ->and((float) StockLevel::query()->sole()->quantity)->toBe(-18.0)
        ->and(app(StockReview::class)->negative($this->outlet)->pluck('inventory_item_id')->all())->toBe([$beans->getKey()]);
});

it('holds later commands behind a failed one until a manager sets it aside', function (): void {
    [$broken, $payment] = saleCommands($this);
    $broken['lines'][0]['variant_id'] = (string) Str::ulid();
    $later = saleCommands($this)[0];

    send($this, $broken)->assertUnprocessable()->assertJsonPath('status', 'failed');
    send($this, $payment)->assertConflict()->assertJsonPath('code', 'blocked');
    send($this, $later)->assertConflict();

    $failed = SyncOperation::query()->where('status', SyncStatus::Failed)->sole();
    app(ResolveOperation::class)->dismiss($failed, $this->manager, 'Dicatat ulang manual');

    send($this, $broken)->assertOk()->assertJsonPath('status', 'dismissed');

    // The payment for the dismissed sale can never apply: its bill is not
    // on the server. It fails in turn and waits for the same decision.
    send($this, $payment)->assertUnprocessable();
    app(ResolveOperation::class)->dismiss(SyncOperation::query()->where('status', SyncStatus::Failed)->sole(), $this->manager, 'Ikut bill yang dikesampingkan');

    send($this, $payment)->assertOk()->assertJsonPath('status', 'dismissed');
    send($this, $later)->assertOk()->assertJsonPath('status', 'applied');

    expect(AuditLog::query()->where('action', 'sync.dismissed')->count())->toBe(2);
});

it('tells a till with a gap which sequence it expected', function (): void {
    [$order] = saleCommands($this);
    $order['sequence'] = 7;

    send($this, $order)
        ->assertConflict()
        ->assertJsonPath('code', 'sequence_gap')
        ->assertJsonPath('expected_sequence', 1);
});

it('accepts a command resent under a new sequence after the till renumbered', function (): void {
    [$order, $payment] = saleCommands($this);

    send($this, $order)->assertOk();

    // The tablet lost the answer, then renumbered its queue from 1.
    $order['sequence'] = 2;
    send($this, $order)->assertOk()->assertJsonPath('status', 'applied');

    $payment['sequence'] = 2;
    send($this, $payment)->assertOk();

    expect(Order::query()->count())->toBe(1)->and(Payment::query()->count())->toBe(1);
});

it('runs a whole offline shift and counts the drawer against offline cash', function (): void {
    $shiftKey = (string) Str::ulid();

    send($this, command($this, 'shift.opened', ['type' => 'shift.opened', 'client_shift_key' => $shiftKey, 'opening_cash' => 100_000]))->assertOk();

    foreach (saleCommands($this, extra: ['client_shift_key' => $shiftKey]) as $each) {
        send($this, $each)->assertOk();
    }

    send($this, command($this, 'cash.moved', ['client_shift_key' => $shiftKey, 'direction' => 'out', 'amount' => 10_000, 'reason' => 'Beli galon']))->assertOk();
    send($this, command($this, 'shift.closed', ['type' => 'shift.closed', 'client_shift_key' => $shiftKey, 'counted_cash' => 114_000, 'note' => null]))
        ->assertOk()
        ->assertJsonPath('difference', -1_000);

    $shift = CashierShift::query()->sole();

    expect($shift->isOpen())->toBeFalse()
        ->and($shift->expected_cash)->toBe(115_000)
        ->and(Order::query()->sole()->cashier_shift_id)->toBe($shift->getKey());
});

it('keeps an offline sale the server prices higher, and flags the difference', function (): void {
    [$order, $payment] = saleCommands($this);

    // The menu went up after the tablet cached it.
    $this->variant->update(['base_price' => 27_000]);

    send($this, $order)->assertOk();
    send($this, $payment)->assertOk()->assertJsonPath('status', 'applied');

    $operation = SyncOperation::query()->where('type', 'payment.recorded')->sole();

    expect($operation->warning)->toContain('2.000')
        ->and(Order::query()->sole()->outstanding())->toBe(2_000);
});

it('serves the menu snapshot an offline till rings up with', function (): void {
    $this->getJson(route('pos.offline-data'))
        ->assertOk()
        ->assertJsonPath('outlet.code', 'KMG')
        ->assertJsonPath('device.prefix', strtoupper(substr((string) $this->device->getKey(), -4)))
        ->assertJsonPath('cash_method.id', $this->cash->getKey())
        ->assertJsonPath('products.0.variants.0.price', 25_000)
        ->assertJsonPath('user.name', $this->cashier->name);
});

it('renders the offline till and a fresh session for the outbox', function (): void {
    $this->get(route('pos.offline'))->assertOk()->assertSee('data-test="offline-terminal"', false);

    $this->getJson(route('sync.session'))
        ->assertOk()
        ->assertJsonPath('user_id', $this->cashier->getKey())
        ->assertJsonStructure(['csrf', 'server_time']);
});

it('lets a manager clear a stuck command from the sync screen', function (): void {
    [$broken] = saleCommands($this);
    $broken['lines'][0]['variant_id'] = (string) Str::ulid();
    send($this, $broken)->assertUnprocessable();

    $this->actingAs($this->manager);

    Livewire::test('pages::sync.index')
        ->assertSee(__('sync.command.order_saved'))
        ->call('startResolving', SyncOperation::query()->sole()->getKey())
        ->set('reason', 'Bill dicatat ulang')
        ->call('resolve')
        ->assertHasNoErrors();

    expect(SyncOperation::query()->sole()->status)->toBe(SyncStatus::Dismissed);
});

it('keeps cashiers out of the sync screen', function (): void {
    $this->get(route('sync.index'))->assertForbidden();
});
