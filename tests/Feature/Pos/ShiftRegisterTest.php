<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\ShiftRegister;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->cash = PaymentMethod::create([
        'name' => 'Tunai',
        'type' => 'cash',
        'opens_cash_drawer' => true,
        'needs_reference' => false,
        'fee_percent' => 0,
    ]);

    $this->qris = PaymentMethod::create([
        'name' => 'QRIS',
        'type' => 'qris',
        'opens_cash_drawer' => false,
        'needs_reference' => false,
        'fee_percent' => 0,
    ]);

    $this->register = app(ShiftRegister::class);
    $this->take = app(TakePayment::class);
});

/** Ring up a bill inside the shift and settle it with the given method. */
function settle(object $test, $shift, PaymentMethod $method, int $total, ?int $tendered = null): Order
{
    $order = Order::factory()->for($test->outlet)->create([
        'user_id' => $test->cashier->id,
        'cashier_shift_id' => $shift->id,
        'grand_total' => $total,
    ]);

    $test->take->handle($order, $method, $test->cashier, $total, $tendered);

    return $order->refresh();
}

it('opens a shift with the counted float', function (): void {
    $shift = $this->register->open($this->outlet, $this->cashier, 200000);

    expect($shift->opening_cash)->toBe(200000)
        ->and($shift->isOpen())->toBeTrue();
});

it('hands back the running shift instead of opening a second one', function (): void {
    $first = $this->register->open($this->outlet, $this->cashier, 200000);
    $second = $this->register->open($this->outlet, $this->cashier, 500000);

    expect($second->id)->toBe($first->id)
        ->and($second->opening_cash)->toBe(200000);
});

it('expects the float plus the cash actually taken', function (): void {
    $shift = $this->register->open($this->outlet, $this->cashier, 200000);

    settle($this, $shift, $this->cash, 50000, tendered: 100000);
    settle($this, $shift, $this->qris, 75000);

    // Only the cash counts, and only the applied amount, not the tendered note.
    expect($this->register->expectedCash($shift))->toBe(250000);
});

it('reports a drawer that is short', function (): void {
    $shift = $this->register->open($this->outlet, $this->cashier, 200000);

    settle($this, $shift, $this->cash, 50000);

    $closed = $this->register->close($shift, 245000);

    expect($closed->expected_cash)->toBe(250000)
        ->and($closed->cash_difference)->toBe(-5000)
        ->and($closed->isOpen())->toBeFalse();
});

it('freezes the expected amount at closing time', function (): void {
    $shift = $this->register->open($this->outlet, $this->cashier, 100000);

    $order = settle($this, $shift, $this->cash, 40000);

    $closed = $this->register->close($shift, 140000);

    // A later void must not rewrite a variance a manager already signed off.
    $order->payments()->delete();

    expect($closed->refresh()->expected_cash)->toBe(140000)
        ->and($closed->cash_difference)->toBe(0);
});

it('refuses to close a shift twice', function (): void {
    $shift = $this->register->open($this->outlet, $this->cashier, 100000);

    $this->register->close($shift, 100000);
    $this->register->close($shift->refresh(), 100000);
})->throws(HttpException::class);

it('breaks the takings down by payment method', function (): void {
    $shift = $this->register->open($this->outlet, $this->cashier, 0);

    settle($this, $shift, $this->cash, 30000);
    settle($this, $shift, $this->cash, 20000);
    settle($this, $shift, $this->qris, 75000);

    $summary = $this->register->summary($shift);

    expect($summary['orders'])->toBe(3)
        ->and($summary['gross'])->toBe(125000)
        ->and($summary['methods']->pluck('total', 'name')->all())
        ->toBe(['QRIS' => 75000, 'Tunai' => 50000]);
});
