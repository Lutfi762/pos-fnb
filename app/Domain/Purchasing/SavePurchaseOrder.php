<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Inventory\Models\InventoryItem;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes a purchase order and its lines.
 *
 * Item names and units are copied onto the line for the same reason a receipt
 * copies a product name: what was ordered has to keep reading the way it read
 * on the day, even after the item is renamed in the master list.
 */
class SavePurchaseOrder
{
    /**
     * @param  list<array{inventory_item_id: string, quantity: float|string, unit_cost: int|string}>  $lines
     * @param  array<string, mixed>  $attributes  supplier_id, expected_at, note.
     */
    public function handle(
        ?PurchaseOrder $order,
        Outlet $outlet,
        User $buyer,
        array $lines,
        array $attributes = [],
    ): PurchaseOrder {
        return DB::transaction(function () use ($order, $outlet, $buyer, $lines, $attributes): PurchaseOrder {
            $order = $order instanceof PurchaseOrder
                ? $this->update($order, $attributes)
                : $this->open($outlet, $buyer, $attributes);

            $this->writeLines($order, $lines);

            return $this->refreshTotals($order);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function open(Outlet $outlet, User $buyer, array $attributes): PurchaseOrder
    {
        return PurchaseOrder::create([
            'outlet_id' => $outlet->getKey(),
            'supplier_id' => $attributes['supplier_id'],
            'user_id' => $buyer->getKey(),
            'number' => $this->nextNumber($outlet),
            'status' => PurchaseOrderStatus::Draft,
            'expected_at' => $attributes['expected_at'] ?? null,
            'note' => $attributes['note'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        abort_unless($order->status->isEditable(), 409, __('purchasing.locked'));

        return tap($order)->update(array_filter([
            'supplier_id' => $attributes['supplier_id'] ?? null,
            'expected_at' => $attributes['expected_at'] ?? null,
            'note' => $attributes['note'] ?? null,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Send the order to the supplier. From here it can only be received.
     */
    public function place(PurchaseOrder $order): PurchaseOrder
    {
        abort_unless($order->status === PurchaseOrderStatus::Draft, 409, __('purchasing.locked'));
        abort_if($order->items()->count() === 0, 422, __('purchasing.no_items'));

        return tap($order)->update([
            'status' => PurchaseOrderStatus::Ordered,
            'ordered_at' => now(),
        ]);
    }

    /**
     * Cancel an order that has not had anything delivered against it.
     */
    public function cancel(PurchaseOrder $order): PurchaseOrder
    {
        abort_unless($order->status->isEditable(), 409, __('purchasing.locked'));
        abort_if($order->hasReceipts(), 409, __('purchasing.partly_received'));

        return tap($order)->update(['status' => PurchaseOrderStatus::Cancelled]);
    }

    /**
     * Rewrite the lines of an order that is still editable.
     *
     * A line that already has a delivery against it is never dropped: what the
     * supplier handed over stays on record even if the buyer edits the order.
     *
     * @param  list<array{inventory_item_id: string, quantity: float|string, unit_cost: int|string}>  $lines
     */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        abort_unless($order->status->isEditable(), 409, __('purchasing.locked'));

        $items = InventoryItem::query()
            ->with('unit')
            ->whereIn('id', array_column($lines, 'inventory_item_id'))
            ->get()
            ->keyBy('id');

        $kept = [];

        foreach ($lines as $line) {
            $item = $items->get($line['inventory_item_id']);
            $quantity = round((float) $line['quantity'], 3);

            if (! $item instanceof InventoryItem || $quantity <= 0) {
                continue;
            }

            $unitCost = max(0, (int) $line['unit_cost']);

            $row = $order->items()->updateOrCreate(
                ['inventory_item_id' => $item->getKey()],
                [
                    'item_name' => $item->name,
                    'unit_code' => $item->unit?->code,
                    'quantity_ordered' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => (int) round($quantity * $unitCost),
                ],
            );

            $kept[] = $row->getKey();
        }

        $order->items()->whereNotIn('id', $kept)->where('quantity_received', 0)->delete();
    }

    /**
     * Add up the lines. Supplier tax is entered inside the unit cost, so the
     * order total is simply the sum of what was ordered.
     */
    private function refreshTotals(PurchaseOrder $order): PurchaseOrder
    {
        $subtotal = (int) $order->items()->sum('line_total');

        $order->update([
            'subtotal' => $subtotal,
            'grand_total' => $subtotal + $order->tax_total,
        ]);

        return $order->fresh(['items', 'supplier']) ?? $order;
    }

    /**
     * Order numbers, formatted PO-{outlet}-{yymm}-{sequence}.
     */
    private function nextNumber(Outlet $outlet): string
    {
        $stem = 'PO-'.$outlet->code.'-'.now()->format('ym').'-';

        // Counting rows would reuse a number after a cancellation, so the last
        // one issued is read back and incremented instead.
        $last = PurchaseOrder::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('number', 'like', $stem.'%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = is_string($last) ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $stem.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
