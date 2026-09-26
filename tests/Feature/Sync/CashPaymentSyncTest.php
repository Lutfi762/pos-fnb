<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create();
    $this->user = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->order = Order::factory()->for($this->outlet)->create(['user_id' => $this->user->id, 'grand_total' => 50_000]);
    $this->cash = PaymentMethod::create([
        'name' => 'Tunai',
        'type' => 'cash',
        'opens_cash_drawer' => true,
        'needs_reference' => false,
        'fee_percent' => 0,
    ]);
    $this->actingAs($this->user);
    app(DeviceRegistry::class)->current($this->outlet);
});

function cashPaymentPayload(object $test, int $sequence = 1): array
{
    return [
        'operation_key' => (string) Str::ulid(),
        'sequence' => $sequence,
        'client_created_at' => now()->toISOString(),
        'order_id' => $test->order->getKey(),
        'payment_method_id' => $test->cash->getKey(),
        'amount' => 50_000,
        'tendered' => 50_000,
    ];
}

it('applies a replayed cash payment once', function (): void {
    $payload = cashPaymentPayload($this);

    $this->postJson(route('sync.cash-payments.store'), $payload)->assertOk();
    $this->postJson(route('sync.cash-payments.store'), $payload)->assertOk();

    expect(Payment::query()->count())->toBe(1)
        ->and(SyncOperation::query()->where('type', 'payment.recorded')->count())->toBe(1);
});

it('refuses a non-cash offline payment', function (): void {
    $card = PaymentMethod::create([
        'name' => 'Kartu Debit',
        'type' => 'card',
        'opens_cash_drawer' => false,
        'needs_reference' => true,
        'fee_percent' => 1,
    ]);
    $payload = cashPaymentPayload($this);
    $payload['payment_method_id'] = $card->getKey();

    $this->postJson(route('sync.cash-payments.store'), $payload)->assertUnprocessable();

    expect(Payment::query()->count())->toBe(0);
});
