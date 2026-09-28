<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Audit\Audit;
use App\Domain\Sales\Models\Payment;
use App\Models\User;

/**
 * A manager checking static QRIS payments against the bank statement.
 *
 * A printed QRIS standee tells nobody when it is paid. The cashier keys the
 * reference from the guest's screen (and can photograph it); the money is
 * only known to be there once someone has seen it in the account.
 */
class StaticQrisReview
{
    public function __construct(private readonly Audit $audit) {}

    public function verify(Payment $payment, User $manager, ?string $note = null): Payment
    {
        return $this->decide($payment, $manager, Payment::VERIFIED, $note);
    }

    /**
     * The money is not in the account. The bill stays as it was rung up; the
     * rejection is recorded so the shortfall shows and can be chased.
     */
    public function reject(Payment $payment, User $manager, string $note): Payment
    {
        abort_if(trim($note) === '', 422, __('qris.note_required'));

        return $this->decide($payment, $manager, Payment::REJECTED, $note);
    }

    private function decide(Payment $payment, User $manager, string $verdict, ?string $note): Payment
    {
        abort_unless($payment->verification === Payment::UNVERIFIED, 409, __('qris.already_checked'));

        $payment->update([
            'verification' => $verdict,
            'verified_by' => $manager->getKey(),
            'verified_at' => now(),
            'verification_note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);

        $payment->loadMissing('order');

        $this->audit->record('payment.'.$verdict, $payment, ['verification' => Payment::UNVERIFIED], [
            'verification' => $verdict,
            'amount' => $payment->amount,
            'order' => $payment->order?->number,
        ], $payment->verification_note, $manager, outletId: $payment->order?->outlet_id);

        return $payment;
    }
}
