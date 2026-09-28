<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use App\Domain\Sales\Models\PaymentCharge;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Dynamic QRIS through Xendit's QR Codes API (api-version 2022-07-31).
 *
 * Checked against Xendit's published docs, not yet against a live sandbox
 * account: run the checklist in README ("QRIS dengan Xendit") with sandbox
 * keys before the first real shift.
 */
class XenditGateway implements PaymentGateway
{
    private const API_VERSION = '2022-07-31';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'xendit';
    }

    public function createQris(PaymentCharge $charge): IssuedCode
    {
        $expires = CarbonImmutable::now()->addMinutes((int) config('payments.qris_expiry_minutes', 10));

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['idempotency-key' => $charge->reference])
            ->post('/qr_codes', [
                'reference_id' => $charge->reference,
                'type' => 'DYNAMIC',
                'currency' => 'IDR',
                'amount' => $charge->amount,
                'expires_at' => $expires->utc()->toIso8601ZuluString(),
            ]));

        $id = (string) $response->json('id');
        $qr = (string) $response->json('qr_string');

        if ($id === '' || $qr === '') {
            throw new GatewayException(__('qris.gateway_error', ['message' => 'no qr_string']));
        }

        return new IssuedCode($id, $qr, $this->time($response->json('expires_at')) ?? $expires);
    }

    public function status(PaymentCharge $charge): ChargeState
    {
        if ($charge->provider_charge_id === null) {
            return ChargeState::unpaid();
        }

        $response = $this->send(fn (PendingRequest $http) => $http
            ->get('/qr_codes/'.rawurlencode($charge->provider_charge_id).'/payments'));

        foreach ((array) $response->json('data', []) as $payment) {
            if (($payment['status'] ?? null) === 'SUCCEEDED') {
                return new ChargeState(
                    paid: true,
                    amount: (int) ($payment['amount'] ?? 0),
                    providerPaymentId: isset($payment['id']) ? (string) $payment['id'] : null,
                    paidAt: $this->time($payment['created'] ?? null),
                );
            }
        }

        return ChargeState::unpaid(closed: $charge->isExpired());
    }

    /**
     * The 2022-07-31 API has no call to switch off a dynamic code. It stops
     * working at expires_at, which is why codes are issued for minutes, not
     * hours. A guest who pays in the gap is caught by the webhook and lands
     * on the manager's review list as money to give back.
     */
    public function cancel(PaymentCharge $charge): void {}

    public function refund(PaymentCharge $charge, int $amount, string $reference, string $reason): RefundResult
    {
        if ($charge->provider_payment_id === null) {
            throw new GatewayException(__('qris.refund_no_payment'));
        }

        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['idempotency-key' => $reference])
            ->post('/qr_codes/payments/'.rawurlencode($charge->provider_payment_id).'/refunds', [
                'reference_id' => $reference,
                'type' => $amount >= $charge->paid_amount ? 'FULL' : 'PARTIAL',
                'currency' => 'IDR',
                'amount' => $amount,
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'metadata' => ['note' => mb_substr($reason, 0, 250)],
            ]));

        return new RefundResult((string) $response->json('id'), strtolower((string) $response->json('status', 'pending')));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $expected = (string) ($this->config['webhook_token'] ?? '');
        $given = (string) $request->header('x-callback-token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            throw new InvalidSignature('x-callback-token does not match');
        }

        $body = json_decode($request->getContent(), true);

        if (! is_array($body)) {
            throw new InvalidSignature('body is not JSON');
        }

        $type = (string) ($body['event'] ?? 'unknown');
        $data = (array) ($body['data'] ?? []);
        $status = (string) ($data['status'] ?? '');

        return new WebhookEvent(
            eventId: $type.':'.($data['id'] ?? sha1($request->getContent())),
            type: $type,
            kind: $type === 'qr.payment' && $status === 'SUCCEEDED' ? WebhookEvent::PAID : WebhookEvent::OTHER,
            reference: isset($data['reference_id']) ? (string) $data['reference_id'] : null,
            providerChargeId: isset($data['qr_id']) ? (string) $data['qr_id'] : null,
            providerPaymentId: isset($data['id']) ? (string) $data['id'] : null,
            amount: (int) ($data['amount'] ?? 0),
            paidAt: $this->time($data['created'] ?? null),
        );
    }

    public function settlements(CarbonInterface $from, CarbonInterface $to): array
    {
        $lines = [];
        $after = null;

        // The Transactions API pages by id; a busy day is a few pages at most.
        for ($page = 0; $page < 50; $page++) {
            $response = $this->send(fn (PendingRequest $http) => $http->get('/transactions', array_filter([
                'types' => 'PAYMENT',
                'channel_categories' => 'QR_CODE',
                'created[gte]' => $from->toImmutable()->utc()->toIso8601ZuluString(),
                'created[lte]' => $to->toImmutable()->utc()->toIso8601ZuluString(),
                'limit' => 50,
                'after_id' => $after,
            ])));

            foreach ((array) $response->json('data', []) as $row) {
                $fee = (array) ($row['fee'] ?? []);
                $amount = (int) round((float) ($row['amount'] ?? 0));
                $feeTotal = (int) round((float) ($fee['xendit_fee'] ?? 0) + (float) ($fee['value_added_tax'] ?? 0)
                    + (float) ($fee['xendit_withholding_tax'] ?? 0) + (float) ($fee['third_party_withholding_tax'] ?? 0));

                $lines[] = new SettlementLine(
                    providerTransactionId: (string) ($row['id'] ?? ''),
                    reference: isset($row['reference_id']) ? (string) $row['reference_id'] : null,
                    amount: $amount,
                    fee: $feeTotal,
                    netAmount: isset($row['net_amount']) ? (int) round((float) $row['net_amount']) : $amount - $feeTotal,
                    settlementStatus: isset($row['settlement_status']) ? (string) $row['settlement_status'] : null,
                    transactedAt: $this->time($row['created'] ?? null) ?? CarbonImmutable::now(),
                );

                $after = (string) ($row['id'] ?? $after);
            }

            if (! $response->json('has_more')) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $key = (string) ($this->config['secret_key'] ?? '');

        if ($key === '') {
            throw new GatewayException(__('qris.not_configured'));
        }

        $http = Http::baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://api.xendit.co'), '/'))
            ->withBasicAuth($key, '')
            ->withHeaders(['api-version' => self::API_VERSION])
            ->acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 15));

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw new GatewayException(__('qris.gateway_unreachable'));
        }

        if ($response->failed()) {
            throw new GatewayException(__('qris.gateway_error', [
                'message' => (string) ($response->json('message') ?? $response->json('error_code') ?? $response->status()),
            ]));
        }

        return $response;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
