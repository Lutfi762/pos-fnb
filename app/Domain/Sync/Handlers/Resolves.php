<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Sales\Models\CashierShift;
use App\Domain\Sales\Models\Order;
use App\Domain\Sync\SyncContext;

/**
 * Finds the rows a queued command refers to.
 *
 * A terminal that was offline only knows the keys it minted itself, so every
 * reference may arrive either as a server id or as the device's own key.
 */
trait Resolves
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function order(SyncContext $context, array $payload): Order
    {
        $query = Order::query()->where('outlet_id', $context->outlet->getKey())->lockForUpdate();

        if (is_string($payload['order_id'] ?? null) && $payload['order_id'] !== '') {
            return $query->whereKey($payload['order_id'])->firstOrFail();
        }

        return $query->where('client_order_key', (string) ($payload['client_order_key'] ?? ''))->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shift(SyncContext $context, array $payload): ?CashierShift
    {
        $query = CashierShift::query()->where('outlet_id', $context->outlet->getKey());

        if (is_string($payload['shift_id'] ?? null) && $payload['shift_id'] !== '') {
            return $query->whereKey($payload['shift_id'])->first();
        }

        if (is_string($payload['client_shift_key'] ?? null) && $payload['client_shift_key'] !== '') {
            return $query->where('client_shift_key', $payload['client_shift_key'])->first();
        }

        return null;
    }
}
