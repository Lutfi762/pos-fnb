<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock ever moves.
 *
 * Every change is written twice: once as a movement that can be read back
 * later to answer "who took this and why", and once as the running balance
 * the POS reads. The ledger is the truth; the balance is the shortcut.
 */
class MoveStock
{
    /**
     * Record one movement and return it with the balance it left behind.
     *
     * @param  float  $quantity  Signed for adjustments; the sign is forced for types that only go one way.
     * @param  int  $unitCost  What one base unit cost, for receipts.
     */
    public function record(
        Outlet $outlet,
        InventoryItem $item,
        StockMovementType $type,
        float $quantity,
        ?User $user = null,
        int $unitCost = 0,
        ?Model $source = null,
        ?string $note = null,
        ?CarbonInterface $at = null,
        ?string $reason = null,
    ): StockMovement {
        $quantity = $this->signed($type, $quantity);

        abort_if(abs($quantity) < 0.001, 422, __('inventory.quantity_required'));

        return DB::transaction(function () use ($outlet, $item, $type, $quantity, $user, $unitCost, $source, $note, $at, $reason): StockMovement {
            $level = $this->lockedLevel($outlet, $item);

            $balance = round((float) $level->quantity + $quantity, 3);

            // Cost is averaged before the balance moves, because the old
            // quantity is what the old cost applies to.
            if ($quantity > 0 && $unitCost > 0) {
                $this->reaverage($item, $quantity, $unitCost);
            }

            $level->update(['quantity' => $balance]);

            return StockMovement::create([
                'outlet_id' => $outlet->getKey(),
                'inventory_item_id' => $item->getKey(),
                'user_id' => $user?->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $balance,
                'unit_cost' => $unitCost > 0 ? $unitCost : $item->average_cost,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'note' => $note,
                'reason' => $reason,
                // When it happened on the floor, which for a sale rung up
                // offline is earlier than when the server heard about it.
                'moved_at' => $at ?? now(),
            ]);
        });
    }

    /**
     * A stock count: the shelf is right, the books get corrected to match.
     *
     * Nothing is written when the count agrees with the books, so a clean
     * count does not litter the ledger with zero rows.
     */
    public function count(
        Outlet $outlet,
        InventoryItem $item,
        float $counted,
        ?User $user = null,
        ?string $note = null,
    ): ?StockMovement {
        return DB::transaction(function () use ($outlet, $item, $counted, $user, $note): ?StockMovement {
            $level = $this->lockedLevel($outlet, $item);
            $difference = round($counted - (float) $level->quantity, 3);

            $level->update(['counted_at' => now()]);

            if (abs($difference) < 0.001) {
                return null;
            }

            return $this->record($outlet, $item, StockMovementType::Count, $difference, $user, note: $note);
        });
    }

    /**
     * Set the level at which this item lands on the restock list.
     */
    public function setReorderPoint(Outlet $outlet, InventoryItem $item, float $point): StockLevel
    {
        return tap($this->level($outlet, $item))->update([
            'reorder_point' => max(0, $point),
        ]);
    }

    /**
     * The balance row for this item at this outlet, created on first touch.
     */
    public function level(Outlet $outlet, InventoryItem $item): StockLevel
    {
        return StockLevel::firstOrCreate([
            'outlet_id' => $outlet->getKey(),
            'inventory_item_id' => $item->getKey(),
        ]);
    }

    /**
     * The same row, held for the rest of the transaction so two receipts
     * cannot both read the old balance and write over each other.
     */
    private function lockedLevel(Outlet $outlet, InventoryItem $item): StockLevel
    {
        $level = $this->level($outlet, $item);

        return StockLevel::query()->whereKey($level->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Weighted average cost, in whole rupiah, across every outlet.
     *
     * Stock already on hand keeps the price it was bought at; only the new
     * delivery is priced at the new cost. A balance at or below zero has no
     * history worth averaging, so the delivery simply sets the cost.
     */
    private function reaverage(InventoryItem $item, float $incoming, int $unitCost): void
    {
        $onHand = (float) $item->stockLevels()->sum('quantity');

        $average = $onHand <= 0
            ? $unitCost
            : (int) round((($onHand * $item->average_cost) + ($incoming * $unitCost)) / ($onHand + $incoming));

        $item->update(['average_cost' => $average]);
    }

    /**
     * Sales and waste only ever reduce stock, and a purchase only ever adds.
     * The rest carry their own sign, because a correction goes both ways.
     */
    private function signed(StockMovementType $type, float $quantity): float
    {
        if ($type->isOutgoing()) {
            return -abs($quantity);
        }

        return in_array($type, [StockMovementType::Purchase, StockMovementType::Return], true) ? abs($quantity) : $quantity;
    }
}
