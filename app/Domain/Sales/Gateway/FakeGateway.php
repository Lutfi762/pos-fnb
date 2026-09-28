<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use App\Domain\Sales\Enums\ChargeStatus;
use App\Domain\Sales\Models\PaymentCharge;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A gateway that lives inside the app, for trying the QRIS flow without an
 * account and for the test suite.
 *
 * It behaves like the real thing where it matters: codes expire, a paid code
 * reports as paid when asked, and its webhooks are signed and go through the
 * same check a real one does. "Paying" is the test button on the payment
 * screen, which calls pay().
 */
class FakeGateway implements PaymentGateway
{
    public const SIGNATURE_HEADER = 'x-fake-signature';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createQris(PaymentCharge $charge): IssuedCode
    {
        $id = 'fakeqr_'.Str::lower((string) Str::ulid());

        return new IssuedCode(
            $id,
            // Shaped like a QRIS payload so it renders as a real-looking code.
            '00020101021226570011ID.MEJA.WWW0118'.$id.'5204581253033605405'.$charge->amount.'5802ID5904MEJA6007JAKARTA6304FAKE',
            CarbonImmutable::now()->addMinutes((int) config('payments.qris_expiry_minutes', 10)),
        );
    }

    public function status(PaymentCharge $charge): ChargeState
    {
        $paid = Cache::get($this->key($charge));

        if (is_array($paid)) {
            return new ChargeState(true, (int) $paid['amount'], (string) $paid['id'], CarbonImmutable::parse($paid['paid_at']));
        }

        return ChargeState::unpaid(closed: $charge->isExpired());
    }

    public function cancel(PaymentCharge $charge): void {}

    public function refund(PaymentCharge $charge, int $amount, string $reference, string $reason): RefundResult
    {
        if ($charge->provider_payment_id === null) {
            throw new GatewayException(__('qris.refund_no_payment'));
        }

        return new RefundResult('fakerf_'.Str::lower((string) Str::ulid()), 'succeeded');
    }

    /**
     * The guest scans and pays: remember it, and hand back the webhook the
     * gateway would send, signed.
     */
    public function pay(PaymentCharge $charge, ?int $amount = null): Request
    {
        $payment = [
            'id' => 'fakepay_'.Str::lower((string) Str::ulid()),
            'amount' => $amount ?? $charge->amount,
            'paid_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        Cache::put($this->key($charge), $payment, now()->addDay());

        $body = (string) json_encode([
            'event' => 'qr.payment',
            'id' => $payment['id'],
            'status' => 'SUCCEEDED',
            'reference' => $charge->reference,
            'charge_id' => $charge->provider_charge_id,
            'amount' => $payment['amount'],
            'paid_at' => $payment['paid_at'],
        ]);

        return $this->signedRequest($body);
    }

    public function signedRequest(string $body, ?string $signature = null): Request
    {
        $request = Request::create('/webhooks/payments/fake', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set(self::SIGNATURE_HEADER, $signature ?? $this->sign($body));

        return $request;
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) ($this->config['webhook_secret'] ?? ''));
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $body = $request->getContent();
        $given = (string) $request->header(self::SIGNATURE_HEADER, '');

        if (($this->config['webhook_secret'] ?? '') === '' || ! hash_equals($this->sign($body), $given)) {
            throw new InvalidSignature('signature does not match');
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new InvalidSignature('body is not JSON');
        }

        return new WebhookEvent(
            eventId: (string) ($data['id'] ?? sha1($body)),
            type: (string) ($data['event'] ?? 'unknown'),
            kind: ($data['status'] ?? null) === 'SUCCEEDED' ? WebhookEvent::PAID : WebhookEvent::OTHER,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            providerChargeId: isset($data['charge_id']) ? (string) $data['charge_id'] : null,
            providerPaymentId: isset($data['id']) ? (string) $data['id'] : null,
            amount: (int) ($data['amount'] ?? 0),
            paidAt: isset($data['paid_at']) ? CarbonImmutable::parse((string) $data['paid_at'])->setTimezone(config('app.timezone')) : null,
        );
    }

    /**
     * What the fake gateway was paid, with its fee taken off, the way a real
     * settlement report reads.
     */
    public function settlements(CarbonInterface $from, CarbonInterface $to): array
    {
        $percent = (float) ($this->config['fee_percent'] ?? 0);

        return array_values(PaymentCharge::query()
            ->where('provider', $this->name())
            ->whereIn('status', [ChargeStatus::Paid, ChargeStatus::Unapplied])
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at')
            ->get()
            ->map(function (PaymentCharge $charge) use ($percent): SettlementLine {
                $fee = (int) floor($charge->paid_amount * $percent / 100);

                return new SettlementLine(
                    providerTransactionId: 'faketx_'.$charge->provider_payment_id,
                    reference: $charge->reference,
                    amount: $charge->paid_amount,
                    fee: $fee,
                    netAmount: $charge->paid_amount - $fee,
                    settlementStatus: 'SETTLED',
                    transactedAt: $charge->paid_at ?? CarbonImmutable::now(),
                );
            })
            ->all());
    }

    private function key(PaymentCharge $charge): string
    {
        return 'fake-gateway:'.$charge->provider_charge_id;
    }
}
