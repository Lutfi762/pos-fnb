<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Kitchen\StationRouter;
use App\Domain\Sales\Enums\OrderItemStatus;
use App\Domain\Sales\Enums\OrderType;
use App\Domain\Sales\Models\DiningTable;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\SaveOrder;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncConflict;
use App\Domain\Sync\SyncContext;
use Illuminate\Validation\Rule;

/**
 * order.saved: a cart written on a terminal while the line was down.
 *
 * Prices and modifiers are rebuilt from the server catalog, never taken from
 * the device: a queued command is a request, and the menu decides what a
 * dish costs. The bill number is the exception. An offline terminal already
 * printed it on the guest's receipt, so a well-formed one is kept.
 */
class SyncDraftOrder implements SyncHandler
{
    use Resolves;

    public function __construct(
        private readonly SaveOrder $save,
        private readonly StationRouter $stations,
    ) {}

    public function rules(): array
    {
        return [
            'client_order_key' => ['required', 'string', 'max:255'],
            'revision' => ['nullable', 'integer', 'min:1'],
            'type' => ['required', Rule::in(array_column(OrderType::cases(), 'value'))],
            'guest_count' => ['required', 'integer', 'min:1', 'max:99'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:40'],
            'table_id' => ['nullable', 'string', 'max:40'],
            'shift_id' => ['nullable', 'string', 'max:40'],
            'client_shift_key' => ['nullable', 'string', 'max:255'],
            // Set by the offline terminal, which told the kitchen itself with
            // a printed docket. Firing the lines again later would put food
            // that was served an hour ago back on the board.
            'kitchen_handled' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.variant_id' => ['required', 'string'],
            'lines.*.modifier_ids' => ['present', 'array', 'max:20'],
            'lines.*.modifier_ids.*' => ['string', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function apply(SyncContext $context, array $payload): array
    {
        $outlet = $context->outlet;

        $order = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('client_order_key', $payload['client_order_key'])
            ->lockForUpdate()
            ->first();

        if ($order instanceof Order && ($payload['revision'] ?? null) !== $order->revision) {
            throw new SyncConflict(__('sync.order_changed'), ['order_id' => $order->getKey(), 'revision' => $order->revision]);
        }

        $type = OrderType::from($payload['type']);
        $shift = $this->shift($context, $payload);

        $attributes = [
            'type' => $type,
            'guest_count' => $payload['guest_count'],
            'customer_name' => $payload['customer_name'] ?? null,
            'dining_table_id' => $type->needsTable() ? $this->table($outlet, $payload['table_id'] ?? null) : null,
            'cashier_shift_id' => $shift?->getKey(),
        ];

        if (! $order instanceof Order) {
            $attributes['placed_at'] = $context->happenedAt();
            $attributes['number'] = $this->number($context, $payload['number'] ?? null);
        }

        $order = $this->save->handle($order, $outlet, $context->user, $this->lines($payload['lines'], $outlet), $attributes);

        if ($order->client_order_key === null) {
            $order->update(['client_order_key' => $payload['client_order_key']]);
        }

        if (($payload['kitchen_handled'] ?? false) === true) {
            $order->items()->where('status', OrderItemStatus::Pending)->update([
                'status' => OrderItemStatus::Served,
                'sent_at' => $order->placed_at,
                'ready_at' => $order->placed_at,
            ]);
        }

        $order->refresh();

        return [
            'order_id' => $order->getKey(),
            'revision' => $order->revision,
            'number' => $order->number,
            'grand_total' => $order->grand_total,
        ];
    }

    /**
     * A table at this outlet, or none. A table deleted since the device last
     * synced is dropped rather than failing the whole sale.
     */
    private function table(Outlet $outlet, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        return DiningTable::query()->where('outlet_id', $outlet->getKey())->whereKey($id)->value('id');
    }

    /**
     * Keep a number the device minted, as long as it is one this terminal
     * could have minted and nobody else has used it. Otherwise the server
     * issues one, and the old receipt still finds the bill by its key.
     */
    private function number(SyncContext $context, ?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $prefix = $context->outlet->code.'-'.strtoupper(substr((string) $context->device->getKey(), -4)).'-';
        $wellFormed = str_starts_with($number, $prefix) && preg_match('/-\d{6}-\d{4}$/', $number) === 1;

        if (! $wellFormed) {
            return null;
        }

        $taken = Order::query()->where('outlet_id', $context->outlet->getKey())->where('number', $number)->exists();

        return $taken ? null : $number;
    }

    /**
     * @param  list<array{variant_id: string, modifier_ids: list<string>, quantity: int, note: string|null}>  $payload
     * @return list<array<string, mixed>>
     */
    private function lines(array $payload, Outlet $outlet): array
    {
        return array_map(function (array $line) use ($outlet): array {
            $variant = ProductVariant::query()->with(['product.category', 'product.modifierGroups.modifiers', 'outletPrices'])->findOrFail($line['variant_id']);

            abort_unless($variant->is_active && $variant->product->is_active, 422, __('sync.product_unavailable'));

            $modifiers = $this->modifiers($variant, $line['modifier_ids']);

            return [
                'product_variant_id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'station' => $this->stations->stationFor($variant->product)->value,
                'prep_minutes' => $this->stations->prepMinutesFor($variant->product),
                'quantity' => $line['quantity'],
                'unit_price' => $variant->priceFor($outlet),
                'modifier_total' => array_sum(array_column($modifiers, 'price')),
                'note' => $line['note'] ?? null,
                'modifiers' => $modifiers,
            ];
        }, $payload);
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{modifier_id: string, modifier_name: string, group_name: string, price: int}>
     */
    private function modifiers(ProductVariant $variant, array $ids): array
    {
        $rows = [];

        foreach ($variant->product->modifierGroups as $group) {
            $selected = $group->modifiers->whereIn('id', $ids)->values();

            abort_unless($selected->count() >= $group->min_select && $selected->count() <= $group->max_select, 422, __('sync.modifier_invalid'));

            foreach ($selected as $modifier) {
                abort_unless($group->is_active && $modifier->is_active, 422, __('sync.modifier_unavailable'));

                $rows[] = [
                    'modifier_id' => $modifier->getKey(),
                    'modifier_name' => $modifier->name,
                    'group_name' => $group->name,
                    'price' => $modifier->price,
                ];
            }
        }

        abort_unless(count($rows) === count($ids), 422, __('sync.modifier_invalid'));

        return $rows;
    }
}
