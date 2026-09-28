<?php

declare(strict_types=1);

namespace App\Domain\Sales\Jobs;

use App\Domain\Sales\Gateway\WebhookEvent;
use App\Domain\Sales\Models\GatewayWebhook;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sales\QrisPayments;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Put a confirmed QRIS payment on its bill.
 *
 * Safe to run twice: capturing a charge that is already captured does
 * nothing. The payment screen also asks the gateway directly every few
 * seconds, so a queue worker that is not running delays nothing a cashier
 * can see; it only leaves the webhook row marked "received".
 */
class ProcessGatewayWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array{kind: string, reference: string|null, provider_charge_id: string|null, provider_payment_id: string|null, amount: int, paid_at: string|null}  $event
     */
    public function __construct(
        public readonly string $webhookId,
        public readonly array $event,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(QrisPayments $qris): void
    {
        $webhook = GatewayWebhook::query()->find($this->webhookId);

        if (! $webhook instanceof GatewayWebhook || $webhook->status === GatewayWebhook::PROCESSED) {
            return;
        }

        if ($this->event['kind'] !== WebhookEvent::PAID) {
            $webhook->update(['status' => GatewayWebhook::IGNORED, 'processed_at' => now()]);

            return;
        }

        $charge = PaymentCharge::query()
            ->when(
                $this->event['reference'] !== null,
                fn ($query) => $query->where('reference', $this->event['reference']),
                fn ($query) => $query->where('provider_charge_id', $this->event['provider_charge_id']),
            )
            ->first();

        if (! $charge instanceof PaymentCharge) {
            // A code this till never issued, perhaps another app on the same
            // account. Kept for the record, not retried.
            $webhook->update(['status' => GatewayWebhook::IGNORED, 'error' => 'unknown charge', 'processed_at' => now()]);

            return;
        }

        try {
            $qris->capture(
                $charge,
                (int) $this->event['amount'],
                $this->event['provider_payment_id'],
                $this->event['paid_at'] !== null ? CarbonImmutable::parse($this->event['paid_at']) : null,
            );
        } catch (\Throwable $exception) {
            $webhook->update(['status' => GatewayWebhook::FAILED, 'error' => mb_substr($exception->getMessage(), 0, 250), 'payment_charge_id' => $charge->getKey()]);

            throw $exception;
        }

        $webhook->update(['status' => GatewayWebhook::PROCESSED, 'error' => null, 'payment_charge_id' => $charge->getKey(), 'processed_at' => now()]);
    }
}
