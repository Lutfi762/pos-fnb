<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Models\DomainModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property PurchaseOrderStatus $status
 */
class PurchaseOrder extends DomainModel
{
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Whether any part of this order has already been delivered, which is what
     * stops it being cancelled: the stock is on the shelf either way.
     */
    public function hasReceipts(): bool
    {
        return $this->items()->where('quantity_received', '>', 0)->exists();
    }
}
