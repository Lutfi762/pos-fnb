<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Inventory\DeductSaleStock;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Takes money against a bill.
 *
 * A split payment is the normal case here, not an edge case: a table pays part
 * in cash and the rest by card. So every tender is its own row, and the order
 * only closes once nothing is outstanding.
 */
class TakePayment
{
    public const STATUS_CAPTURED = 'captured';

    /**
     * @param  int  $amount  What the guest is putting against the bill.
     * @param  int|null  $tendered  Cash handed over, when it is more than the amount.
     */
    public function handle(
        Order $order,
        PaymentMethod $method,
        User $cashier,
        int $amount,
        ?int $tendered = null,
        ?string $reference = null,
        ?CarbonInterface $paidAt = null,
        ?string $proofPath = null,
    ): Payment {
        abort_unless($order->status->isEditable(), 409, __('pos.order_closed'));
        abort_unless($amount > 0, 422, __('payments.amount_required'));

        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);

        abort_if($method->needs_reference && $reference === null, 422, __('payments.reference_required'));

        // A sale rung up offline keeps the moment the guest paid, not the
        // moment the tablet found a connection again.
        $paidAt ??= now();

        return DB::transaction(function () use ($order, $method, $cashier, $amount, $tendered, $reference, $paidAt, $proofPath): Payment {
            // Re-read under a lock: two terminals must never both close the bill.
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $outstanding = $order->outstanding();

            abort_unless($outstanding > 0, 409, __('payments.already_settled'));

            // Anything over what is owed is change, never revenue. Only a cash
            // drawer gives change back; a card is charged for the exact amount.
            $applied = min($amount, $outstanding);
            $handed = $method->opens_cash_drawer ? max($tendered ?? $amount, $applied) : $applied;
            $change = $handed - $applied;

            $payment = $order->payments()->create([
                'payment_method_id' => $method->getKey(),

                // Snapshot: renaming a payment method must not rewrite a receipt.
                'method_name' => $method->name,

                'amount' => $applied,
                'tendered' => $handed,
                'change' => $change,
                'fee_amount' => $method->feeFor($applied),
                'reference' => $reference,
                'proof_path' => $proofPath,

                // A printed QRIS standee tells nobody it was paid: a manager
                // checks these against the bank statement later.
                'verification' => $method->isStaticQris() ? Payment::UNVERIFIED : null,
                'status' => self::STATUS_CAPTURED,
                'user_id' => $cashier->getKey(),
                'paid_at' => $paidAt,
            ]);

            $paid = $order->paid_total + $applied;

            $order->update([
                'paid_total' => $paid,
                'change_total' => $order->change_total + $change,
            ] + ($paid >= $order->grand_total ? [
                'status' => OrderStatus::Paid,
                'paid_at' => $paidAt,
            ] : []));

            // Settled in full: the dishes are made, so their ingredients go.
            if ($order->status === OrderStatus::Paid) {
                app(DeductSaleStock::class)->handle($order, $cashier, $paidAt);
            }

            return $payment;
        });
    }

    /**
     * Undo the last tender on a bill that is still open, for a mis-key.
     *
     * ponytail: a settled bill needs a refund with its own approval trail,
     * which is a different action. This only walks back an open bill.
     */
    public function voidLast(Order $order): void
    {
        abort_unless($order->status->isEditable(), 409, __('pos.order_closed'));

        $payment = $order->payments()->latest('paid_at')->first();

        if (! $payment instanceof Payment) {
            return;
        }

        // Money a gateway confirmed has left the guest's account. Taking it
        // off the bill here would lose it; it goes back as a refund.
        abort_if($payment->payment_charge_id !== null, 409, __('qris.cannot_void'));

        DB::transaction(function () use ($order, $payment): void {
            $order->update([
                'paid_total' => max(0, $order->paid_total - $payment->amount),
                'change_total' => max(0, $order->change_total - $payment->change),
            ]);

            $payment->delete();
        });
    }
}
