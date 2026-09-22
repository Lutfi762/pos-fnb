<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;

class SequencedOperation
{
    /**
     * Reject a gap or an overtaking command. The caller holds its transaction,
     * so two requests from one device cannot both claim the same next sequence.
     */
    public function assertCanApply(Device $device, string $operationKey, int $sequence): void
    {
        Device::query()->whereKey($device->getKey())->lockForUpdate()->sole();

        $existing = SyncOperation::query()
            ->where('device_id', $device->getKey())
            ->where('sequence', $sequence)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof SyncOperation) {
            abort_unless($existing->operation_key === $operationKey, 409, 'Sequence already belongs to another operation.');

            return;
        }

        $blocked = SyncOperation::query()
            ->where('device_id', $device->getKey())
            ->where('sequence', '<', $sequence)
            ->whereIn('status', [SyncStatus::Pending->value, SyncStatus::Failed->value, SyncStatus::Conflict->value])
            ->exists();

        abort_if($blocked, 409, 'An earlier device operation needs attention.');

        $previous = SyncOperation::query()
            ->where('device_id', $device->getKey())
            ->max('sequence');

        abort_unless($sequence === ((int) $previous + 1), 409, 'Device operation sequence has a gap.');
    }
}
