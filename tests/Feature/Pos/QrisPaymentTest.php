<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\ChargeStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Models\GatewayWebhook;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\QrisPayments;
use App\Domain\Sales\ReceiveWebhook;
use App\Domain\Sales\RefundOrder;
use App\Domain\Sales\TakePayment;
use App\Models\User;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->outlet = Outlet::factory()->create(['tax_percent' => 0, 'service_charge_percent' => 0, 'cash_rounding' => 0]);
    $this->cashier = User::factory()->atOutlet($this->outlet, Role::Cashier)->create();
    $this->manager = User::factory()->atOutlet($this->outlet, Role::Manager)->create(['pin_hash' => '654321']);

    $this->cash = PaymentMethod::create([
        'name' => 'Tunai', 'type' => 'cash', 'mode' => PaymentMethod::MANUAL, 'opens_cash_drawer' => true, 'needs_reference' => false, 'fee_percent' => 0, 'sort_order' => 1,
    ]);
    $this->qris = PaymentMethod::create([
        'name' => 'QRIS', 'type' => 'qris', 'mode' => PaymentMethod::GATEWAY_QRIS, 'opens_cash_drawer' => false, 'needs_reference' => false, 'fee_percent' => 0.7, 'sort_order' => 2,
    ]);

    $this->order = Order::factory()->for($this->outlet)->create([
        'user_id' => $this->cashier->id,
        'subtotal' => 50_000,
        'grand_total' => 50_000,
    ]);
    $this->line = OrderItem::create([
        'order_id' => $this->order->id, 'product_name' => 'Kopi Susu', 'variant_name' => 'Reguler', 'quantity' => 2, 'unit_price' => 25_000, 'line_total' => 50_000,
    ]);

    $this->gateway = app(PaymentGateway::class);
    $this->payments = app(QrisPayments::class);

    $this->actingAs($this->cashier);
    app(CurrentOutlet::class)->set($this->outlet);
});

/**
 * The guest pays the code, and the fake gateway's signed webhook arrives.
 */
function guestPays(object $test, PaymentCharge $charge, ?int $amount = null): GatewayWebhook
{
    return app(ReceiveWebhook::class)->handle('fake', $test->gateway->pay($charge, $amount));
}

it('issues a code for what is owed and remembers it', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    expect($charge->status)->toBe(ChargeStatus::Pending)
        ->and($charge->provider)->toBe('fake')
        ->and($charge->qr_string)->not->toBeEmpty()
        ->and($charge->provider_charge_id)->toStartWith('fakeqr_')
        ->and($charge->expires_at->isFuture())->toBeTrue()
        ->and($this->order->refresh()->status)->not->toBe(OrderStatus::Paid);
});

it('refuses a code for more than is owed, or for a method without a gateway', function (): void {
    expect(fn () => $this->payments->start($this->order, $this->qris, $this->cashier, 60_000))->toThrow(HttpException::class)
        ->and(fn () => $this->payments->start($this->order, $this->cash, $this->cashier, 10_000))->toThrow(HttpException::class)
        ->and(PaymentCharge::query()->count())->toBe(0);
});

it('replaces the code on screen instead of showing two', function (): void {
    $first = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);
    $second = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    expect($first->refresh()->status)->toBe(ChargeStatus::Cancelled)
        ->and($second->status)->toBe(ChargeStatus::Pending);
});

it('puts the money on the bill when the signed webhook arrives, once', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    $request = $this->gateway->pay($charge);
    app(ReceiveWebhook::class)->handle('fake', $request);
    app(ReceiveWebhook::class)->handle('fake', $request);

    $payment = Payment::query()->sole();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($payment->amount)->toBe(50_000)
        ->and($payment->payment_charge_id)->toBe($charge->id)
        ->and($payment->fee_amount)->toBe(350)
        ->and($payment->user_id)->toBe($this->cashier->id)
        ->and($charge->refresh()->status)->toBe(ChargeStatus::Paid)
        ->and($charge->paid_amount)->toBe(50_000)
        ->and(GatewayWebhook::query()->count())->toBe(1)
        ->and(GatewayWebhook::query()->sole()->status)->toBe(GatewayWebhook::PROCESSED)
        ->and(GatewayWebhook::query()->sole()->payload)->toBe($request->getContent());
});

it('takes webhooks over HTTP without a session or CSRF token', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);
    $request = $this->gateway->pay($charge);

    auth()->logout();

    $this->call('POST', '/webhooks/payments/fake', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $request->header('x-fake-signature'),
    ], content: $request->getContent())->assertOk()->assertJson(['received' => true]);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('turns away a webhook with a bad signature and keeps it as evidence', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);
    $body = $this->gateway->pay($charge)->getContent();

    $this->call('POST', '/webhooks/payments/fake', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => 'forged',
    ], content: $body)->assertUnauthorized();

    // Tampered amount with the old signature.
    $this->call('POST', '/webhooks/payments/fake', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_FAKE_SIGNATURE' => $this->gateway->sign($body),
    ], content: str_replace('50000', '5000', $body))->assertUnauthorized();

    expect(Payment::query()->count())->toBe(0)
        ->and(GatewayWebhook::query()->where('status', GatewayWebhook::REJECTED)->count())->toBe(2)
        ->and(GatewayWebhook::query()->where('signature_valid', true)->count())->toBe(0);
});

it('refuses webhooks for a gateway this install does not use', function (): void {
    $this->postJson('/webhooks/payments/xendit', ['event' => 'qr.payment'])->assertNotFound();
});

it('finds the payment by asking the gateway when no webhook comes', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    // Paid on the gateway's side; the webhook never reached this laptop.
    $this->gateway->pay($charge);

    $this->payments->check($charge, force: true);

    expect($charge->refresh()->status)->toBe(ChargeStatus::Paid)
        ->and($this->order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('does not ask the gateway more often than configured', function (): void {
    config(['payments.status_check_seconds' => 60]);
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);
    $this->gateway->pay($charge);

    $this->payments->check($charge);

    expect($charge->refresh()->status)->toBe(ChargeStatus::Pending);

    $this->travel(61)->seconds();
    $this->payments->check($charge);

    expect($charge->refresh()->status)->toBe(ChargeStatus::Paid);
});

it('closes a code that ran out of time', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    $this->travel(config('payments.qris_expiry_minutes') + 1)->minutes();
    $this->payments->check($charge);

    expect($charge->refresh()->status)->toBe(ChargeStatus::Expired)
        ->and($this->order->refresh()->paid_total)->toBe(0);
});

it('splits a bill between QRIS and cash', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 30_000);
    guestPays($this, $charge);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Open)
        ->and($this->order->outstanding())->toBe(20_000);

    app(TakePayment::class)->handle($this->order, $this->cash, $this->cashier, 20_000, tendered: 20_000);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->payments()->pluck('amount', 'method_name')->all())->toBe(['QRIS' => 30_000, 'Tunai' => 20_000]);
});

it('keeps money paid after the bill closed aside for a manager, never on the bill', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    // The guest gave up waiting and paid cash; then their bank app went through.
    app(TakePayment::class)->handle($this->order, $this->cash, $this->cashier, 50_000, tendered: 50_000);
    guestPays($this, $charge);

    $charge->refresh();

    expect($charge->status)->toBe(ChargeStatus::Unapplied)
        ->and($charge->unapplied_amount)->toBe(50_000)
        ->and($charge->needsAttention())->toBeTrue()
        ->and($this->order->refresh()->paid_total)->toBe(50_000)
        ->and($this->order->payments()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'qris.unapplied')->count())->toBe(1);

    $this->actingAs($this->manager);

    Livewire::test('pages::payments.review')
        ->assertSee($this->order->number)
        ->call('resolve', $charge->id)
        ->assertHasErrors('notes.'.$charge->id)
        ->set('notes.'.$charge->id, 'Dikembalikan tunai ke tamu')
        ->call('resolve', $charge->id)
        ->assertHasNoErrors();

    expect($charge->refresh()->needsAttention())->toBeFalse()
        ->and($charge->resolved_by)->toBe($this->manager->id);
});

it('will not void a QRIS payment the gateway confirmed', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 30_000);
    guestPays($this, $charge);

    app(TakePayment::class)->voidLast($this->order->refresh());
})->throws(HttpException::class);

it('refunds back through the code the guest paid with', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);
    guestPays($this, $charge);

    $refund = app(RefundOrder::class)->handle(
        $this->order->refresh(), $this->cashier, $this->manager, [$this->line->id => 1], 'Salah pesan', false, $this->qris,
    );

    expect($refund->amount)->toBe(25_000)
        ->and($refund->payment_charge_id)->toBe($charge->id)
        ->and($refund->gateway_reference)->toStartWith('fakerf_')
        ->and($refund->gateway_status)->toBe('succeeded');
});

it('refuses a gateway refund no single code can cover, and keeps nothing of it', function (): void {
    guestPays($this, $this->payments->start($this->order, $this->qris, $this->cashier, 25_000));
    guestPays($this, $this->payments->start($this->order->refresh(), $this->qris, $this->cashier, 25_000));

    expect(fn () => app(RefundOrder::class)->handle(
        $this->order->refresh(), $this->cashier, $this->manager, [$this->line->id => 2], 'Batal semua', false, $this->qris,
    ))->toThrow(HttpException::class);

    expect($this->order->refresh()->refunded_total)->toBe(0)
        ->and($this->order->refunds()->count())->toBe(0);
});

it('shows the code, then the receipt once the guest pays, on the payment screen', function (): void {
    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->set('methodId', $this->qris->id)
        ->assertSee(__('qris.show_code', ['amount' => 'Rp50.000']))
        ->call('showQris')
        ->assertSee(__('qris.scan'))
        ->assertSee(__('qris.simulate'))
        ->call('checkCharge')
        ->assertNoRedirect()
        ->call('simulatePay')
        ->assertRedirect(route('pos.receipt', ['order' => $this->order]));

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('picks a showing code back up after a reload instead of issuing another', function (): void {
    $charge = $this->payments->start($this->order, $this->qris, $this->cashier, 50_000);

    Livewire::test('pages::pos.payment', ['order' => $this->order])
        ->assertSet('chargeId', $charge->id)
        ->assertSet('methodId', $this->qris->id)
        ->set('methodId', $this->cash->id)
        ->assertSet('methodId', $this->qris->id)
        ->call('cancelCharge')
        ->assertSet('chargeId', null);

    expect($charge->refresh()->status)->toBe(ChargeStatus::Cancelled);
});
