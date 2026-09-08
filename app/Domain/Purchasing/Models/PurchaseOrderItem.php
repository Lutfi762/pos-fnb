<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\InventoryItem;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends DomainModel
{
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * What is still owed by the supplier on this line.
     */
    public function outstandingQuantity(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }
}
