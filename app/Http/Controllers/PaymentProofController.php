<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Sales\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The photo a cashier took of a guest's banking app, for the manager
 * checking static QRIS payments. Kept off the public disk: it can show a
 * guest's name and account.
 */
class PaymentProofController
{
    public function __invoke(Payment $payment, CurrentOutlet $outlet): StreamedResponse
    {
        $payment->loadMissing('order');

        abort_unless($payment->order?->outlet_id === $outlet->getOrFail()->getKey(), 404);
        abort_if($payment->proof_path === null || ! Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path, headers: [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
