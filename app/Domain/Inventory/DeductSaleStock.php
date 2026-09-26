<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\Models\RecipeItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Takes the ingredients of a settled bill off the shelf.
 *
 * Runs once, when the bill is paid in full: that is the moment the coffee is
 * definitely made. Stock may go below zero here. A sale is never refused for
 * lack of stock, because the cup is already in the guest's hand; a negative
 * balance is a count waiting to be done, and the stock screen lists it.
 */
class DeductSaleStock
{
    public function __construct(private readonly MoveStock $stock) {}

    /**
     * @return int Number of movements written. Zero on a repeat call.
     */
    public function handle(Order $order, ?User $user = null, ?CarbonInterface $at = null): int
    {
        return DB::transaction(function () use ($order, $user, $at): int {
            // Idempotent: a replayed payment must not empty the shelf twice.
            $already = StockMovement::query()
                ->where('source_type', $order->getMorphClass())
                ->where('source_id', $order->getKey())
                ->where('type', StockMovementType::Sale)
                ->exists();

            if ($already) {
                return 0;
            }

            $order->loadMissing(['items', 'outlet']);

            $usage = $this->usage($order);

            if ($usage === []) {
                return 0;
            }

            $items = InventoryItem::query()
                ->whereIn('id', array_keys($usage))
                ->get()
                ->keyBy('id');

            $written = 0;

            foreach ($usage as $itemId => $quantity) {
                $item = $items->get($itemId);

                if (! $item instanceof InventoryItem || abs($quantity) < 0.001) {
                    continue;
                }

                $this->stock->record(
                    $order->outlet,
                    $item,
                    StockMovementType::Sale,
                    $quantity,
                    $user,
                    source: $order,
                    note: $order->number,
                    at: $at ?? $order->paid_at,
                );

                $written++;
            }

            return $written;
        });
    }

    /**
     * Ingredient totals for the bill: one row per ingredient, summed across
     * every line that uses it, so a latte and a cappuccino share one milk row.
     *
     * @return array<string, float> inventory item id to quantity used
     */
    public function usage(Order $order): array
    {
        $lines = $order->items->reject(
            fn (OrderItem $item): bool => $item->status === OrderItemStatus::Voided || $item->product_variant_id === null,
        );

        if ($lines->isEmpty()) {
            return [];
        }

        $recipes = RecipeItem::query()
            ->whereIn('product_variant_id', $lines->pluck('product_variant_id')->unique()->all())
            ->get()
            ->groupBy('product_variant_id');

        $usage = [];

        foreach ($lines as $line) {
            foreach ($recipes->get($line->product_variant_id, collect()) as $recipe) {
                $key = (string) $recipe->inventory_item_id;
                $usage[$key] = round(($usage[$key] ?? 0) + (float) $recipe->quantity * (float) $line->quantity, 3);
            }
        }

        return $usage;
    }
}
