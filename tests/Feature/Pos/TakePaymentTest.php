<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();

    $this->order = Order::factory()->for($this->outlet)->create([
        'user_id' => $this->cashier->id,
        'grand_total' => 50000,
    ]);

    $this->cash = PaymentMethod::create([
        'name' => 'Tunai',
        'type' => 'cash',
        'opens_cash_drawer' => true,
        'needs_reference' => false,
        'fee_percent' => 0,
    ]);

    $this->card = PaymentMethod::create([
        'name' => 'Kartu Debit',
        'type' => 'card',
        'opens_cash_drawer' => false,
        'needs_reference' => true,
        'fee_percent' => 1,
    ]);

    $this->take = app(TakePayment::class);
});

it('settles a bill paid with exact cash', function (): void {
    $payment = $this->take->handle($this->order, $this->cash, $this->cashier, 50000);

    expect($payment->amount)->toBe(50000)
        ->and($payment->change)->toBe(0);

    $this->order->refresh();

    expect($this->order->status)->toBe(OrderStatus::Paid)
        ->and($this->order->paid_total)->toBe(50000)
        ->and($this->order->paid_at)->not->toBeNull();
});

it('gives change back when more cash is handed over', function (): void {
    $payment = $this->take->handle($this->order, $this->cash, $this->cashier, 50000, tendered: 100000);

    expect($payment->tendered)->toBe(100000)
        ->and($payment->change)->toBe(50000)
        ->and($payment->amount)->toBe(50000);

    expect($this->order->refresh()->change_total)->toBe(50000);
});

it('never books an overpayment as revenue', function (): void {
    // The cashier keys the tendered amount into the amount box by mistake.
    $payment = $this->take->handle($this->order, $this->cash, $this->cashier, 100000);

    expect($payment->amount)->toBe(50000)
        ->and($payment->change)->toBe(50000);

    expect($this->order->refresh()->paid_total)->toBe(50000);
});

it('keeps a bill open until a split payment is complete', function (): void {
    $this->take->handle($this->order, $this->cash, $this->cashier, 20000);

    $this->order->refresh();

    expect($this->order->status)->toBe(OrderStatus::Open)
        ->and($this->order->outstanding())->toBe(30000);

    $this->take->handle($this->order, $this->card, $this->cashier, 30000, reference: '4321');

    $this->order->refresh();

    expect($this->order->status)->toBe(OrderStatus::Paid)
        ->and($this->order->payments()->count())->toBe(2);
});

it('records the merchant fee against a card payment', function (): void {
    $payment = $this->take->handle($this->order, $this->card, $this->cashier, 50000, reference: '4321');

    expect($payment->fee_amount)->toBe(500);
});

it('refuses a card payment without a reference', function (): void {
    $this->take->handle($this->order, $this->card, $this->cashier, 50000);
})->throws(HttpException::class);

it('refuses to take money twice on a settled bill', function (): void {
    $this->take->handle($this->order, $this->cash, $this->cashier, 50000);
    $this->take->handle($this->order->refresh(), $this->cash, $this->cashier, 10000);
})->throws(HttpException::class);

it('walks back the last tender on an open bill', function (): void {
    $this->take->handle($this->order, $this->cash, $this->cashier, 20000);

    $this->take->voidLast($this->order->refresh());

    $this->order->refresh();

    expect($this->order->paid_total)->toBe(0)
        ->and($this->order->payments()->count())->toBe(0)
        ->and($this->order->status)->toBe(OrderStatus::Open);
});
