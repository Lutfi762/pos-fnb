<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Ingredients the books say are below zero.
 *
 * A negative balance is not an error. It means sales went through for stock
 * the books did not know about, which happens when two tills sell the last
 * bag of beans while offline, or when a delivery was never entered. Either
 * way the shelf has to be counted, and this is the list of what to count.
 */
class StockReview
{
    /**
     * @return Collection<int, StockLevel>
     */
    public function negative(Outlet $outlet): Collection
    {
        $levels = StockLevel::query()
            ->with('item.unit')
            ->where('outlet_id', $outlet->getKey())
            ->where('quantity', '<', 0)
            ->orderBy('quantity')
            ->get();

        if ($levels->isEmpty()) {
            return $levels;
        }

        // When it went under, from the last sale: the count is most useful
        // when done close to that moment.
        $lastSale = StockMovement::query()
            ->where('outlet_id', $outlet->getKey())
            ->whereIn('inventory_item_id', $levels->pluck('inventory_item_id'))
            ->where('type', StockMovementType::Sale)
            ->selectRaw('inventory_item_id, max(moved_at) as last_sold_at')
            ->groupBy('inventory_item_id')
            ->pluck('last_sold_at', 'inventory_item_id');

        return $levels->each(fn (StockLevel $level) => $level->setAttribute(
            'last_sold_at',
            $lastSale->get($level->inventory_item_id),
        ));
    }
}
