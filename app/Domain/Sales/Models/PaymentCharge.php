<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\ChargeStatus;
use App\Models\DomainModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One dynamic QRIS code issued by the gateway for one bill.
 *
 * @property string $id
 * @property string $outlet_id
 * @property string $order_id
 * @property string|null $payment_method_id
 * @property int $user_id
 * @property string $provider
 * @property string $reference
 * @property string|null $provider_charge_id
 * @property string|null $provider_payment_id
 * @property int $amount
 * @property int $paid_amount
 * @property int $unapplied_amount
 * @property ChargeStatus $status
 * @property string|null $qr_string
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $checked_at
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 */
class PaymentCharge extends DomainModel
{
    protected function casts(): array
    {
        return [
            'status' => ChargeStatus::class,
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'unapplied_amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'checked_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Money the guest paid that is not on any bill and nobody has dealt with.
     */
    public function needsAttention(): bool
    {
        return $this->unapplied_amount > 0 && $this->resolved_at === null;
    }
}
