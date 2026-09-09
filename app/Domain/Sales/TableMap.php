<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\DiningTable;
use App\Domain\Sales\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The floor plan: which table is free, seated, or carrying an open bill.
 */
class TableMap
{
    /**
     * Tables with their open order attached, ready to draw.
     *
     * @return Collection<int, DiningTable>
     */
    public function forOutlet(Outlet $outlet): Collection
    {
        $open = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('status', OrderStatus::Open)
            ->whereNotNull('dining_table_id')
            ->whereNull('merged_into_order_id')
            ->get(['id', 'number', 'dining_table_id', 'grand_total', 'guest_count'])
            ->keyBy('dining_table_id');

        return DiningTable::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('is_active', true)
            ->orderBy('pos_y')
            ->orderBy('pos_x')
            ->get()
            ->each(fn (DiningTable $table) => $table->setAttribute(
                'open_order',
                $open->get($table->getKey()),
            ));
    }

    /**
     * Move a running bill to another table.
     */
    public function move(Order $order, DiningTable $target): Order
    {
        abort_unless($order->status->isEditable(), 409, __('pos.order_closed'));
        abort_unless($target->outlet_id === $order->outlet_id, 403);

        return tap($order)->update(['dining_table_id' => $target->getKey()]);
    }

    /**
     * Fold one bill into another, freeing the source table.
     *
     * The source order is kept, marked as merged rather than deleted, so the
     * trail from "we sat at M4" to "we paid at M7" survives an audit.
     */
    public function merge(Order $source, Order $target): Order
    {
        abort_unless($source->getKey() !== $target->getKey(), 422, __('pos.merge_same'));
        abort_unless($source->status->isEditable() && $target->status->isEditable(), 409, __('pos.order_closed'));
        abort_unless($source->outlet_id === $target->outlet_id, 403);

        return DB::transaction(function () use ($source, $target): Order {
            $source->items()->update(['order_id' => $target->getKey()]);

            $source->update([
                'status' => OrderStatus::Voided,
                'merged_into_order_id' => $target->getKey(),
                'dining_table_id' => null,
                'void_reason' => __('pos.merged_into', ['number' => $target->number]),
                'voided_at' => now(),
                'subtotal' => 0,
                'discount_total' => 0,
                'service_charge_total' => 0,
                'tax_total' => 0,
                'rounding' => 0,
                'grand_total' => 0,
            ]);

            // The target now carries both sets of lines, so its bill is redone.
            return app(SaveOrder::class)->recalculate($target);
        });
    }
}
