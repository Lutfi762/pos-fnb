<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\StationRouter;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Enums\OrderType;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Writes a cart to an order, then recalculates the bottom of the bill.
 *
 * Names and prices are copied onto the line, never joined at read time: a
 * receipt has to keep saying what the guest actually agreed to pay, even
 * after the menu is renamed or repriced.
 */
class SaveOrder
{
    public function __construct(
        private readonly CalculateTotals $totals,
        private readonly OrderNumber $numbers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines  Cart rows from the POS.
     * @param  array<string, mixed>  $attributes  type, dining_table_id, guest_count, discounts.
     */
    public function handle(
        ?Order $order,
        Outlet $outlet,
        User $cashier,
        array $lines,
        array $attributes = [],
    ): Order {
        return DB::transaction(function () use ($order, $outlet, $cashier, $lines, $attributes): Order {
            $order = $order instanceof Order
                ? $this->update($order, $attributes)
                : $this->open($outlet, $cashier, $attributes);

            $this->writeLines($order, $lines);

            return $this->refreshTotals($order, $outlet, $attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function open(Outlet $outlet, User $cashier, array $attributes): Order
    {
        return Order::create([
            'outlet_id' => $outlet->getKey(),
            'user_id' => $cashier->getKey(),
            'cashier_shift_id' => $attributes['cashier_shift_id'] ?? null,
            'dining_table_id' => $attributes['dining_table_id'] ?? null,
            'number' => $this->numbers->next($outlet),
            'type' => $attributes['type'] ?? OrderType::DineIn,
            'status' => OrderStatus::Open,
            'guest_count' => $attributes['guest_count'] ?? 1,
            'customer_name' => $attributes['customer_name'] ?? null,
            'note' => $attributes['note'] ?? null,

            // Snapshot: changing the outlet tax later must not rewrite this bill.
            'tax_percent' => $outlet->tax_percent,
            'service_charge_percent' => $outlet->service_charge_percent,
            'price_includes_tax' => $outlet->price_includes_tax,

            'placed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(Order $order, array $attributes): Order
    {
        abort_unless($order->status->isEditable(), 409, __('pos.order_closed'));

        return tap($order)->update(array_filter([
            'dining_table_id' => $attributes['dining_table_id'] ?? null,
            'type' => $attributes['type'] ?? null,
            'guest_count' => $attributes['guest_count'] ?? null,
            'customer_name' => $attributes['customer_name'] ?? null,
            'note' => $attributes['note'] ?? null,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Rewrite the lines of an editable order.
     *
     * A line already sent to a station keeps its id and status, so the kitchen
     * screen does not see the same dish appear twice.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(Order $order, array $lines): void
    {
        $kept = [];

        foreach ($lines as $line) {
            $cart = self::cartLine($line);

            $values = [
                'product_variant_id' => $line['product_variant_id'] ?? null,
                'product_name' => $line['product_name'],
                'variant_name' => $line['variant_name'] ?? null,
                'station' => $line['station'] ?? null,

                // Snapshot, like the name and the price: the ticket keeps the
                // target it was fired with even if the category is retimed.
                'prep_minutes' => (int) ($line['prep_minutes'] ?? StationRouter::DEFAULT_PREP_MINUTES),

                'quantity' => $cart->quantity,
                'unit_price' => $cart->unitPrice,

                // Stored for the whole line; CartLine holds it per unit.
                'modifier_total' => $cart->modifierTotal * $cart->quantity,

                'discount_total' => $cart->discount(),
                'line_total' => $cart->net(),
                'note' => $line['note'] ?? null,
            ];

            $id = $line['id'] ?? null;

            $row = is_string($id)
                ? tap($order->items()->findOrFail($id))->update($values)
                : $order->items()->create($values + ['status' => OrderItemStatus::Pending]);

            $this->writeModifiers($row, $line['modifiers'] ?? []);

            $kept[] = $row->getKey();
        }

        // A line the cashier removed before sending is simply gone. Removing a
        // line that was already sent is a void, which is a different action.
        $order->items()->whereNotIn('id', $kept)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $modifiers
     */
    private function writeModifiers(OrderItem $item, array $modifiers): void
    {
        $item->modifiers()->delete();

        foreach ($modifiers as $modifier) {
            $item->modifiers()->create([
                'modifier_id' => $modifier['modifier_id'] ?? null,
                'modifier_name' => $modifier['modifier_name'],
                'group_name' => $modifier['group_name'] ?? null,
                'price' => (int) ($modifier['price'] ?? 0),
                'quantity' => 1,
            ]);
        }
    }

    /**
     * Redo the bottom of a bill whose lines changed outside of a cart save,
     * such as after two tables are merged.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recalculate(Order $order, array $attributes = []): Order
    {
        return $this->refreshTotals($order, $order->outlet, $attributes);
    }

    /**
     * Recalculate the bottom of the bill from the saved lines.
     *
     * The lines are read back from the database rather than trusted from the
     * payload: whatever the screen believed, the receipt follows the rows.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function refreshTotals(Order $order, Outlet $outlet, array $attributes): Order
    {
        $order->load('items');

        $lines = $order->items
            ->reject(fn (OrderItem $item): bool => $item->status === OrderItemStatus::Voided)
            ->map(function (OrderItem $item): CartLine {
                $quantity = max(1, (int) $item->quantity);

                // The row stores the discount already worked out, so it is fed
                // back as a flat amount instead of recomputing a percentage.
                return new CartLine(
                    unitPrice: $item->unit_price,
                    quantity: $quantity,
                    modifierTotal: intdiv($item->modifier_total, $quantity),
                    discountType: DiscountType::Amount,
                    discountValue: $item->discount_total,
                );
            })
            ->values()
            ->all();

        /** @var list<CartLine> $lines */
        $totals = $this->totals->handle(
            $lines,
            TaxProfile::fromOrder($order, (int) $outlet->cash_rounding),
            $attributes['bill_discount_type'] ?? null,
            (int) ($attributes['bill_discount_value'] ?? 0),
        );

        $order->update($totals->toArray());

        return $order->fresh(['items.modifiers']) ?? $order;
    }

    /**
     * A cart row as the calculator sees it.
     *
     * @param  array<string, mixed>  $line
     */
    public static function cartLine(array $line): CartLine
    {
        $type = $line['discount_type'] ?? null;

        return new CartLine(
            unitPrice: (int) $line['unit_price'],
            quantity: (int) ($line['quantity'] ?? 1),
            modifierTotal: (int) ($line['modifier_total'] ?? 0),
            discountType: $type instanceof DiscountType ? $type : DiscountType::tryFrom((string) $type),
            discountValue: (int) ($line['discount_value'] ?? 0),
        );
    }
}
