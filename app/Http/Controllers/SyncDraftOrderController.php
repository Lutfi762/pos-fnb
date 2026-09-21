<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Sync\DeviceRegistry;
use App\Domain\Sync\SyncDraftOrder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyncDraftOrderController
{
    public function __invoke(Request $request, CurrentOutlet $current, DeviceRegistry $devices, SyncDraftOrder $sync): JsonResponse
    {
        $data = $request->validate([
            'operation_key' => ['required', 'string', 'max:255'],
            'client_order_key' => ['required', 'string', 'max:255'],
            'revision' => ['nullable', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['dine_in', 'take_away', 'delivery'])],
            'guest_count' => ['required', 'integer', 'min:1', 'max:99'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.variant_id' => ['required', 'string'],
            'lines.*.modifier_ids' => ['present', 'array', 'max:20'],
            'lines.*.modifier_ids.*' => ['string', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        $outlet = $current->getOrFail();
        $user = $request->user();
        abort_unless($outlet instanceof Outlet && $user instanceof User, 403);

        $result = $sync->handle($outlet, $user, $devices->current($outlet), $data);

        return response()->json([
            'operation_key' => $result['operation']->operation_key,
            'status' => $result['operation']->status->value,
            'order_id' => $result['order']?->getKey(),
            'revision' => $result['order']?->revision,
        ], $result['conflict'] ? 409 : 200);
    }
}
