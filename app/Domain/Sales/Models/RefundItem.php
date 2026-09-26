<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a refund: which dish, how many, and its share of the money.
 */
class RefundItem extends DomainModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
