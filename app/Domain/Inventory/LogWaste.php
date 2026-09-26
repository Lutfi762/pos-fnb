<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\Audit;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Stock thrown away: milk that turned, a latte made with the wrong milk, a
 * dropped tray of croissants.
 *
 * Waste is valued at the average cost on the day it happened and reported on
 * its own line. It is never folded into the cost of what was sold, because
 * "our coffee costs 40%" and "we poured 10% of our milk down the sink" call
 * for two different conversations.
 */
class LogWaste
{
    public const REASONS = ['spilled', 'expired', 'wrong_order', 'damaged', 'other'];

    public function __construct(
        private readonly MoveStock $stock,
        private readonly Audit $audit,
    ) {}

    public function handle(Outlet $outlet, InventoryItem $item, float $quantity, string $reason, ?string $note, User $user): StockMovement
    {
        abort_unless(in_array($reason, self::REASONS, true), 422, __('waste.reason_invalid'));
        abort_unless($quantity > 0, 422, __('inventory.quantity_required'));

        $movement = $this->stock->record(
            $outlet,
            $item,
            StockMovementType::Waste,
            $quantity,
            $user,
            note: $note === null || trim($note) === '' ? null : trim($note),
            reason: $reason,
        );

        $this->audit->record('stock.wasted', $movement, after: [
            'item' => $item->name,
            'quantity' => $quantity,
            'reason' => $reason,
            'value' => $this->value($movement),
        ], note: $movement->note, user: $user, outletId: $outlet->getKey());

        return $movement;
    }

    /**
     * What the thrown away stock was worth, in whole rupiah.
     */
    public function value(StockMovement $movement): int
    {
        return (int) round(abs((float) $movement->quantity) * $movement->unit_cost);
    }

    /**
     * @return Collection<int, StockMovement>
     */
    public function entries(Outlet $outlet, CarbonImmutable $from, CarbonImmutable $to, ?string $reason = null, ?string $itemId = null): Collection
    {
        return StockMovement::query()
            ->with(['item.unit', 'user'])
            ->where('outlet_id', $outlet->getKey())
            ->where('type', StockMovementType::Waste)
            ->whereBetween('moved_at', [$from, $to])
            ->when($reason !== null && $reason !== '', fn ($query) => $query->where('reason', $reason))
            ->when($itemId !== null && $itemId !== '', fn ($query) => $query->where('inventory_item_id', $itemId))
            ->latest('moved_at')
            ->get();
    }
}
