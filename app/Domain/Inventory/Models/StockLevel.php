<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Identity\Models\Outlet;
use App\Models\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends DomainModel
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reorder_point' => 'decimal:3',
            'counted_at' => 'datetime',
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
     * @return BelongsTo<InventoryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * At or under the reorder point, so it belongs on the restock list.
     */
    public function needsRestock(): bool
    {
        return (float) $this->quantity <= (float) $this->reorder_point;
    }
}
