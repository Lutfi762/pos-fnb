<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Enums\OrderType;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\SaveOrder;
use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncDraftOrder
{
    /**
     * @param  array{operation_key: string, client_order_key: string, revision: int|null, type: string, guest_count: int, customer_name: string|null, lines: list<array{variant_id: string, quantity: int, note: string|null}>}  $payload
     * @return array{operation: SyncOperation, order: Order|null, conflict: bool}
     */
    public function handle(Outlet $outlet, User $user, Device $device, array $payload): array
    {
        return DB::transaction(function () use ($outlet, $user, $device, $payload): array {
            $operation = SyncOperation::query()->firstOrCreate(
                ['operation_key' => $payload['operation_key']],
                [
                    'device_id' => $device->getKey(),
                    'outlet_id' => $outlet->getKey(),
                    'user_id' => $user->getKey(),
                    'type' => 'draft_order',
                    'payload' => $payload,
                    'client_created_at' => now(),
                ],
            );

            abort_unless($operation->outlet_id === $outlet->getKey() && $operation->user_id === $user->getKey(), 403);

            if ($operation->status === SyncStatus::Applied) {
                return ['operation' => $operation, 'order' => Order::query()->where('client_order_key', $payload['client_order_key'])->first(), 'conflict' => false];
            }

            $order = Order::query()->where('outlet_id', $outlet->getKey())->where('client_order_key', $payload['client_order_key'])->lockForUpdate()->first();

            if ($order instanceof Order && $payload['revision'] !== $order->revision) {
                $operation->update(['status' => SyncStatus::Conflict, 'attempts' => $operation->attempts + 1, 'error' => 'Order changed on another terminal.', 'processed_at' => now()]);

                return ['operation' => $operation, 'order' => $order, 'conflict' => true];
            }

            $lines = $this->lines($payload['lines'], $outlet);

            $order = app(SaveOrder::class)->handle($order, $outlet, $user, $lines, [
                'type' => OrderType::from($payload['type']),
                'guest_count' => $payload['guest_count'],
                'customer_name' => $payload['customer_name'],
            ]);

            if ($order->client_order_key === null) {
                $order->update(['client_order_key' => $payload['client_order_key']]);
            }

            $operation->update(['status' => SyncStatus::Applied, 'attempts' => $operation->attempts + 1, 'error' => null, 'processed_at' => now()]);
            $device->update(['last_synced_at' => now()]);

            return ['operation' => $operation, 'order' => $order->refresh(), 'conflict' => false];
        });
    }

    /** @param list<array{variant_id: string, quantity: int, note: string|null}> $payload */
    private function lines(array $payload, Outlet $outlet): array
    {
        return array_map(function (array $line) use ($outlet): array {
            $variant = ProductVariant::query()->with(['product.modifierGroups', 'outletPrices'])->findOrFail($line['variant_id']);
            abort_unless($variant->is_active && $variant->product->is_active, 422, 'Produk tidak tersedia.');
            abort_if($variant->product->modifierGroups->isNotEmpty(), 422, 'Produk dengan modifier belum dapat disinkronkan offline.');

            return [
                'product_variant_id' => $variant->getKey(),
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'station' => $variant->product->station,
                'quantity' => $line['quantity'],
                'unit_price' => $variant->priceFor($outlet),
                'modifier_total' => 0,
                'note' => $line['note'],
                'modifiers' => [],
            ];
        }, $payload);
    }
}
