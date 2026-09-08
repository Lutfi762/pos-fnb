<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Modifier;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends DomainModel
{
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'quantity' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Modifier, $this>
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
