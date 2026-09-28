<?php

declare(strict_types=1);

use App\Domain\Sales\Gateway\GatewayException;
use App\Domain\Sales\Gateway\InvalidSignature;
use App\Domain\Sales\Gateway\WebhookEvent;
use App\Domain\Sales\Gateway\XenditGateway;
use App\Domain\Sales\Models\PaymentCharge;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->xendit = new XenditGateway([
        'base_url' => 'https://api.xendit.co',
        'secret_key' => 'xnd_development_secret',
        'webhook_token' => 'callback-token',
        'timeout' => 5,
    ]);

    $this->charge = new PaymentCharge([
        'reference' => 'MEJA-01TEST',
        'amount' => 47_500,
        'provider_charge_id' => 'qr_123',
        'provider_payment_id' => 'qrpy_456',
        'paid_amount' => 47_500,
        'expires_at' => now()->addMinutes(10),
    ]);
});

it('asks for a dynamic IDR code with the reference, amount, and API version', function (): void {
    Http::fake(['api.xendit.co/qr_codes' => Http::response([
        'id' => 'qr_123', 'qr_string' => '00020101021226', 'status' => 'ACTIVE', 'expires_at' => '2026-09-26T10:10:00Z',
    ])]);

    $code = $this->xendit->createQris($this->charge);

    expect($code->providerChargeId)->toBe('qr_123')
        ->and($code->qrString)->toBe('00020101021226')
        ->and($code->expiresAt->utc()->format('Y-m-d H:i'))->toBe('2026-09-26 10:10');

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.xendit.co/qr_codes'
        && $request->method() === 'POST'
        && $request->header('api-version') === ['2022-07-31']
        && $request->header('Authorization') === ['Basic '.base64_encode('xnd_development_secret:')]
        && $request['reference_id'] === 'MEJA-01TEST'
        && $request['type'] === 'DYNAMIC'
        && $request['currency'] === 'IDR'
        && $request['amount'] === 47_500);
});

it('reads a succeeded payment when asked for the status', function (): void {
    Http::fake(['api.xendit.co/qr_codes/qr_123/payments' => Http::response(['data' => [
        ['id' => 'qrpy_456', 'status' => 'SUCCEEDED', 'amount' => 47_500, 'created' => '2026-09-26T03:05:00Z'],
    ], 'has_more' => false])]);

    $state = $this->xendit->status($this->charge);

    expect($state->paid)->toBeTrue()
        ->and($state->amount)->toBe(47_500)
        ->and($state->providerPaymentId)->toBe('qrpy_456')
        ->and($state->paidAt->format('H:i'))->toBe('10:05');
});

it('says what went wrong in words a cashier can read', function (): void {
    Http::fake(['*' => Http::response(['error_code' => 'API_VALIDATION_ERROR', 'message' => 'amount must be at least 1500'], 400)]);

    $this->xendit->createQris($this->charge);
})->throws(GatewayException::class, 'amount must be at least 1500');

it('refuses to run without a secret key', function (): void {
    (new XenditGateway(['webhook_token' => 'x']))->createQris($this->charge);
})->throws(GatewayException::class);

it('accepts a webhook only with the callback token', function (): void {
    $body = (string) json_encode([
        'event' => 'qr.payment',
        'business_id' => 'biz',
        'created' => '2026-09-26T03:05:01Z',
        'data' => [
            'id' => 'qrpy_456', 'qr_id' => 'qr_123', 'reference_id' => 'MEJA-01TEST', 'currency' => 'IDR',
            'amount' => 47_500, 'status' => 'SUCCEEDED', 'created' => '2026-09-26T03:05:00Z',
        ],
    ]);

    $request = Request::create('/webhooks/payments/xendit', 'POST', content: $body);
    $request->headers->set('x-callback-token', 'callback-token');

    $event = $this->xendit->parseWebhook($request);

    expect($event->kind)->toBe(WebhookEvent::PAID)
        ->and($event->eventId)->toBe('qr.payment:qrpy_456')
        ->and($event->reference)->toBe('MEJA-01TEST')
        ->and($event->providerChargeId)->toBe('qr_123')
        ->and($event->amount)->toBe(47_500);

    $forged = Request::create('/webhooks/payments/xendit', 'POST', content: $body);
    $forged->headers->set('x-callback-token', 'guess');

    expect(fn () => $this->xendit->parseWebhook($forged))->toThrow(InvalidSignature::class);
});

it('refunds the QR payment with an idempotency key', function (): void {
    Http::fake(['api.xendit.co/qr_codes/payments/qrpy_456/refunds' => Http::response(['id' => 'qrrf_1', 'status' => 'PENDING'])]);

    $result = $this->xendit->refund($this->charge, 10_000, 'refund-ulid', 'Salah pesan');

    expect($result->providerRefundId)->toBe('qrrf_1')
        ->and($result->status)->toBe('pending');

    Http::assertSent(fn (ClientRequest $request): bool => $request->header('idempotency-key') === ['refund-ulid']
        && $request['type'] === 'PARTIAL'
        && $request['amount'] === 10_000);
});

it('reads the settlement report with fees across pages', function (): void {
    Http::fakeSequence('api.xendit.co/transactions*')
        ->push(['data' => [
            ['id' => 'txn_1', 'reference_id' => 'MEJA-A', 'amount' => 50_000, 'net_amount' => 49_611, 'fee' => ['xendit_fee' => 350, 'value_added_tax' => 39], 'settlement_status' => 'SETTLED', 'created' => '2026-09-25T02:00:00Z'],
        ], 'has_more' => true])
        ->push(['data' => [
            ['id' => 'txn_2', 'reference_id' => 'MEJA-B', 'amount' => 20_000, 'fee' => ['xendit_fee' => 140], 'settlement_status' => 'PENDING', 'created' => '2026-09-25T03:00:00Z'],
        ], 'has_more' => false]);

    $lines = $this->xendit->settlements(now()->subDay()->startOfDay(), now()->subDay()->endOfDay());

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->fee)->toBe(389)
        ->and($lines[0]->netAmount)->toBe(49_611)
        ->and($lines[1]->netAmount)->toBe(19_860)
        ->and($lines[1]->settlementStatus)->toBe('PENDING');

    Http::assertSent(fn (ClientRequest $request): bool => str_contains(urldecode($request->url()), 'after_id=txn_1'));
});
