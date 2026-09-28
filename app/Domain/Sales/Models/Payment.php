<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends DomainModel
{
    // Static QRIS: waiting for a manager to find it on the bank statement.
    public const UNVERIFIED = 'unverified';

    public const VERIFIED = 'verified';

    // The manager could not find the money.
    public const REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tendered' => 'integer',
            'change' => 'integer',
            'fee_amount' => 'integer',
            'settled_amount' => 'integer',
            'settled_fee' => 'integer',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(PaymentCharge::class, 'payment_charge_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
