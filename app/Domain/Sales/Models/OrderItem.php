<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property OrderItemStatus $status
 */
class OrderItem extends DomainModel
{
    protected function casts(): array
    {
        return [
            'status' => OrderItemStatus::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'integer',
            'modifier_total' => 'integer',
            'discount_total' => 'integer',
            'line_total' => 'integer',
            'voided_at' => 'datetime',
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
     * The variant may be soft deleted later; the snapshot columns carry on.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return HasMany<OrderItemModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
