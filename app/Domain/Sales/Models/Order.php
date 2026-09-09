<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Enums\OrderType;
use App\Models\DomainModel;
use App\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrderStatus $status
 * @property OrderType $type
 */
class Order extends DomainModel
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'guest_count' => 'integer',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'service_charge_total' => 'integer',
            'tax_total' => 'integer',
            'rounding' => 'integer',
            'grand_total' => 'integer',
            'paid_total' => 'integer',
            'change_total' => 'integer',
            'tax_percent' => 'decimal:2',
            'service_charge_percent' => 'decimal:2',
            'price_includes_tax' => 'boolean',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * What is still owed. Never negative: overpayment is change, not a balance.
     */
    public function outstanding(): int
    {
        return max(0, $this->grand_total - $this->paid_total);
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
