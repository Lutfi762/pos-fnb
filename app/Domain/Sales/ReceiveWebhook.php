<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Gateway\InvalidSignature;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Jobs\ProcessGatewayWebhook;
use App\Domain\Sales\Models\GatewayWebhook;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;

/**
 * Takes a webhook off the wire: keep it exactly as sent, check it is really
 * from the gateway, and hand the work to the queue so the gateway gets its
 * 200 straight away and does not retry out of impatience.
 */
class ReceiveWebhook
{
    // Headers worth keeping for a dispute. The token itself never is.
    private const KEEP_HEADERS = ['content-type', 'user-agent', 'webhook-id', 'x-forwarded-for'];

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function handle(string $provider, Request $request): GatewayWebhook
    {
        abort_unless($provider === $this->gateway->name(), 404);

        $headers = collect($request->headers->all())
            ->only(self::KEEP_HEADERS)
            ->map(fn (array $values): string => implode(', ', $values))
            ->all();

        try {
            $event = $this->gateway->parseWebhook($request);
        } catch (InvalidSignature $exception) {
            return GatewayWebhook::create([
                'provider' => $provider,
                'signature_valid' => false,
                'payload' => $request->getContent(),
                'headers' => $headers,
                'status' => GatewayWebhook::REJECTED,
                'error' => mb_substr($exception->getMessage(), 0, 250),
            ]);
        }

        // Gateways resend until they hear back. The second copy is noted by
        // its event id and left alone.
        $existing = GatewayWebhook::query()->where('provider', $provider)->where('event_id', $event->eventId)->first();

        if ($existing instanceof GatewayWebhook) {
            return $existing;
        }

        try {
            $webhook = GatewayWebhook::create([
                'provider' => $provider,
                'event_id' => $event->eventId,
                'event_type' => $event->type,
                'signature_valid' => true,
                'payload' => $request->getContent(),
                'headers' => $headers,
                'status' => GatewayWebhook::RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            return GatewayWebhook::query()->where('provider', $provider)->where('event_id', $event->eventId)->firstOrFail();
        }

        ProcessGatewayWebhook::dispatch($webhook->getKey(), [
            'kind' => $event->kind,
            'reference' => $event->reference,
            'provider_charge_id' => $event->providerChargeId,
            'provider_payment_id' => $event->providerPaymentId,
            'amount' => $event->amount,
            'paid_at' => $event->paidAt?->toIso8601String(),
        ]);

        return $webhook->refresh();
    }
}
