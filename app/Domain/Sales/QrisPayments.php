<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Sales\Enums\ChargeStatus;
use App\Domain\Sales\Events\ChargeUpdated;
use App\Domain\Sales\Gateway\GatewayException;
use App\Domain\Sales\Gateway\PaymentGateway;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\PaymentCharge;
use App\Domain\Sales\Models\PaymentMethod;
use App\Domain\Sales\Models\Refund;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dynamic QRIS: show a code for part or all of a bill, and put the money on
 * the bill once the gateway says it arrived.
 *
 * The money is on the bill only when the gateway has confirmed it, by
 * webhook or when asked. Never because a guest held up their phone.
 */
class QrisPayments
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TakePayment $payments,
        private readonly Audit $audit,
    ) {}

    /**
     * Ask the gateway for a code. A code already showing for this bill is
     * cancelled first: one bill, one code on screen.
     */
    public function start(Order $order, PaymentMethod $method, User $cashier, int $amount): PaymentCharge
    {
        abort_unless($method->usesGateway(), 422, __('qris.not_gateway'));
        abort_unless($order->status->isEditable(), 409, __('pos.order_closed'));
        abort_unless($amount > 0, 422, __('payments.amount_required'));
        abort_if($amount > $order->outstanding(), 422, __('qris.more_than_owed'));

        $order->charges()->where('status', ChargeStatus::Pending)->get()
            ->each(fn (PaymentCharge $charge) => $this->cancel($charge));

        $charge = PaymentCharge::create([
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->getKey(),
            'payment_method_id' => $method->getKey(),
            'user_id' => $cashier->getKey(),
            'provider' => $this->gateway->name(),
            'reference' => 'MEJA-'.Str::upper((string) Str::ulid()),
            'amount' => $amount,
            'status' => ChargeStatus::Pending,
        ]);

        try {
            $code = $this->gateway->createQris($charge);
        } catch (GatewayException $exception) {
            $charge->update(['status' => ChargeStatus::Failed, 'failure_reason' => mb_substr($exception->getMessage(), 0, 250)]);

            throw $exception;
        }

        $charge->update([
            'provider_charge_id' => $code->providerChargeId,
            'qr_string' => $code->qrString,
            'expires_at' => $code->expiresAt,
            'checked_at' => now(),
        ]);

        return $charge;
    }

    /**
     * Has it been paid? Reads the gateway at most every few seconds, and
     * closes a code that ran out of time.
     */
    public function check(PaymentCharge $charge, bool $force = false): PaymentCharge
    {
        if ($charge->status !== ChargeStatus::Pending) {
            return $charge;
        }

        $every = (int) config('payments.status_check_seconds', 10);

        if (! $force && ! $charge->isExpired() && $charge->checked_at !== null && $charge->checked_at->diffInSeconds(now()) < $every) {
            return $charge;
        }

        try {
            $state = $this->gateway->status($charge);
        } catch (GatewayException) {
            // Offline for a moment: keep waiting. The webhook may still land.
            $charge->update(['checked_at' => now()]);

            return $charge;
        }

        if ($state->paid) {
            return $this->capture($charge, $state->amount, $state->providerPaymentId, $state->paidAt);
        }

        if ($charge->isExpired() || $state->closed) {
            $charge->update(['status' => ChargeStatus::Expired, 'checked_at' => now()]);
            $this->announce($charge);

            return $charge;
        }

        $charge->update(['checked_at' => now()]);

        return $charge;
    }

    public function cancel(PaymentCharge $charge): PaymentCharge
    {
        if ($charge->status !== ChargeStatus::Pending) {
            return $charge;
        }

        try {
            $this->gateway->cancel($charge);
        } catch (GatewayException) {
            // The code still dies at expires_at, and a late payment is caught.
        }

        $charge->update(['status' => ChargeStatus::Cancelled]);
        $this->announce($charge);

        return $charge;
    }

    /**
     * The gateway says the guest paid. Safe to call any number of times, from
     * a webhook and a status check racing each other.
     */
    public function capture(PaymentCharge $charge, int $amount, ?string $providerPaymentId, ?CarbonInterface $paidAt): PaymentCharge
    {
        $charge = DB::transaction(function () use ($charge, $amount, $providerPaymentId, $paidAt): PaymentCharge {
            $charge = PaymentCharge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($charge->status, [ChargeStatus::Paid, ChargeStatus::Unapplied], true)) {
                return $charge;
            }

            $order = Order::query()->whereKey($charge->order_id)->lockForUpdate()->firstOrFail();
            $amount = $amount > 0 ? $amount : $charge->amount;
            $paidAt ??= now();

            // A code can be paid after the bill was settled another way, or
            // for more than is still owed. The money is real either way; what
            // does not fit on the bill is kept aside for a manager to return.
            $applied = $order->status->isEditable() ? min($amount, $order->outstanding()) : 0;

            if ($applied > 0) {
                $method = PaymentMethod::withTrashed()->findOrFail($charge->payment_method_id);

                $payment = $this->payments->handle(
                    $order,
                    $method,
                    $charge->cashier()->firstOrFail(),
                    $applied,
                    reference: $providerPaymentId ?? $charge->reference,
                    paidAt: $paidAt,
                );

                $payment->update(['payment_charge_id' => $charge->getKey()]);
            }

            $charge->update([
                'status' => $applied > 0 ? ChargeStatus::Paid : ChargeStatus::Unapplied,
                'paid_amount' => $amount,
                'unapplied_amount' => $amount - $applied,
                'provider_payment_id' => $providerPaymentId ?? $charge->provider_payment_id,
                'paid_at' => $paidAt,
                'checked_at' => now(),
            ]);

            if ($amount > $applied) {
                $this->audit->record('qris.unapplied', $charge, after: [
                    'paid' => $amount,
                    'applied' => $applied,
                    'order' => $order->number,
                ], outletId: $charge->outlet_id);
            }

            return $charge;
        });

        $this->announce($charge);

        return $charge;
    }

    /**
     * A manager has dealt with money that did not fit on a bill.
     */
    public function resolve(PaymentCharge $charge, User $manager, string $note): PaymentCharge
    {
        abort_unless($charge->needsAttention(), 409, __('qris.nothing_to_resolve'));
        abort_if(trim($note) === '', 422, __('qris.note_required'));

        $charge->update(['resolved_at' => now(), 'resolved_by' => $manager->getKey(), 'resolution_note' => trim($note)]);

        $this->audit->record('qris.resolved', $charge, after: ['unapplied' => $charge->unapplied_amount], note: trim($note), user: $manager, outletId: $charge->outlet_id);

        return $charge;
    }

    /**
     * Send a refund back through the code the guest paid with. One code has
     * to cover the whole refund: splitting one refund over two codes is not
     * something a guest's banking app shows sensibly.
     */
    public function refund(Refund $refund, Order $order, string $reason): void
    {
        $charges = $order->charges()
            ->where('status', ChargeStatus::Paid)
            ->whereNotNull('provider_payment_id')
            ->withSum('refunds', 'amount')
            ->orderByDesc('paid_amount')
            ->get();

        $charge = $charges->first(fn (PaymentCharge $each): bool => $each->paid_amount - (int) $each->getAttribute('refunds_sum_amount') >= $refund->amount);

        abort_unless($charge instanceof PaymentCharge, 422, __('qris.refund_too_large'));

        try {
            $result = $this->gateway->refund($charge, $refund->amount, $refund->getKey(), $reason);
        } catch (GatewayException $exception) {
            abort(422, $exception->getMessage());
        }

        $refund->update([
            'payment_charge_id' => $charge->getKey(),
            'gateway_reference' => $result->providerRefundId,
            'gateway_status' => $result->status,
        ]);
    }

    /**
     * Tell the till showing this code to look again. Only after the database
     * has it, and never at the cost of the payment: a websocket that is down
     * must not undo money that arrived.
     */
    private function announce(PaymentCharge $charge): void
    {
        DB::afterCommit(function () use ($charge): void {
            try {
                event(new ChargeUpdated($charge->outlet_id, $charge->getKey()));
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
