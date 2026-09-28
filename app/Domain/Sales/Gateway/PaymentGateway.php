<?php

declare(strict_types=1);

namespace App\Domain\Sales\Gateway;

use App\Domain\Sales\Models\PaymentCharge;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * What the till needs from a payment gateway, and nothing more.
 *
 * Every method either returns what the gateway said or throws
 * GatewayException with a message a cashier can read. Nothing here touches
 * the database: the caller decides what an answer means for the bill.
 */
interface PaymentGateway
{
    /**
     * Short machine name, stored on every charge and webhook.
     */
    public function name(): string;

    /**
     * Ask for a dynamic QRIS code for exactly this amount.
     */
    public function createQris(PaymentCharge $charge): IssuedCode;

    /**
     * Whether the guest has paid, asked directly rather than waiting for a
     * webhook.
     */
    public function status(PaymentCharge $charge): ChargeState;

    /**
     * Stop a code from being paid, where the gateway allows it.
     */
    public function cancel(PaymentCharge $charge): void;

    /**
     * Send money back to the guest's wallet or bank.
     */
    public function refund(PaymentCharge $charge, int $amount, string $reference, string $reason): RefundResult;

    /**
     * Check a webhook is really from the gateway and read it.
     *
     * @throws InvalidSignature
     */
    public function parseWebhook(Request $request): WebhookEvent;

    /**
     * What the gateway collected on one day, with its fees.
     *
     * @return list<SettlementLine>
     */
    public function settlements(CarbonInterface $from, CarbonInterface $to): array;
}
