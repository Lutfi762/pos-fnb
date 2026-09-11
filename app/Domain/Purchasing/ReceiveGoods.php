<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Inventory\MoveStock;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Books a delivery against a purchase order.
 *
 * A delivery is what turns a piece of paper into stock, so this is the only
 * place a purchase touches the ledger. Deliveries arrive in parts often
 * enough that partial is the normal case: two crates today, one on Friday.
 */
class ReceiveGoods
{
    public function __construct(private readonly MoveStock $stock) {}

    /**
     * @param  array<string, float|string>  $quantities  Line id to the amount that arrived now.
     */
    public function handle(PurchaseOrder $order, User $receiver, array $quantities, ?string $note = null): PurchaseOrder
    {
        abort_unless($order->status->isReceivable(), 409, __('purchasing.not_receivable'));

        return DB::transaction(function () use ($order, $receiver, $quantities, $note): PurchaseOrder {
            $order->load('items.item.unit');
            $moved = false;

            foreach ($order->items as $line) {
                $arrived = round((float) ($quantities[$line->getKey()] ?? 0), 3);

                if ($arrived <= 0) {
                    continue;
                }

                // Over-delivery is capped at what was ordered. A supplier who
                // sends more than the sheet says needs a new line, not a
                // silent edit of the one that was approved.
                $arrived = min($arrived, $line->outstandingQuantity());

                if ($arrived <= 0) {
                    continue;
                }

                $this->receiveLine($order, $line, $receiver, $arrived, $note);
                $moved = true;
            }

            abort_unless($moved, 422, __('purchasing.nothing_received'));

            return $this->settle($order);
        });
    }

    /**
     * Move one line into stock and remember how much of it has landed.
     */
    private function receiveLine(
        PurchaseOrder $order,
        PurchaseOrderItem $line,
        User $receiver,
        float $arrived,
        ?string $note,
    ): void {
        $item = $line->item;

        if (! $item instanceof InventoryItem) {
            return;
        }

        $this->stock->record(
            outlet: $order->outlet,
            item: $item,
            type: StockMovementType::Purchase,
            quantity: $arrived,
            user: $receiver,
            unitCost: $line->unit_cost,
            source: $order,
            note: $note,
        );

        $line->update([
            'quantity_received' => round((float) $line->quantity_received + $arrived, 3),
        ]);
    }

    /**
     * Move the order to whatever its lines now say it is.
     */
    private function settle(PurchaseOrder $order): PurchaseOrder
    {
        $order->load('items');

        $complete = $order->items->every(
            fn (PurchaseOrderItem $line): bool => $line->outstandingQuantity() < 0.001,
        );

        $order->update([
            'status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived,
            'received_at' => $complete ? now() : $order->received_at,
        ]);

        return $order->fresh(['items', 'supplier']) ?? $order;
    }
}
