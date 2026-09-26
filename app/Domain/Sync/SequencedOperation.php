<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Sync\Enums\SyncStatus;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Keeps one terminal's commands in the order they happened.
 *
 * A cash payment must never be applied before the sale it pays for, and a
 * shift must never be closed before the last sale rung up in it. So a device
 * sends its commands numbered, and the server refuses a number out of turn.
 */
class SequencedOperation
{
    /**
     * Reject a gap or an overtaking command. The caller holds its transaction,
     * so two requests from one device cannot both claim the same next sequence.
     *
     * The refusal says what the server expected, so a terminal whose numbering
     * drifted (its storage was cleared, say) can renumber and try again.
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
            if ($existing->operation_key !== $operationKey) {
                $this->refuse('sequence_taken', __('sync.sequence_taken'), $this->next($device));
            }

            return;
        }

        // The same command resent under a new number after the terminal
        // renumbered its queue. It is still the same command.
        $known = SyncOperation::query()->where('operation_key', $operationKey)->first();

        if ($known instanceof SyncOperation) {
            return;
        }

        $blocked = SyncOperation::query()
            ->where('device_id', $device->getKey())
            ->where('sequence', '<', $sequence)
            ->whereIn('status', SyncStatus::blocking())
            ->exists();

        if ($blocked) {
            $this->refuse('blocked', __('sync.blocked_by_earlier'), $this->next($device));
        }

        $expected = $this->next($device);

        if ($sequence !== $expected) {
            $this->refuse('sequence_gap', __('sync.sequence_gap'), $expected);
        }
    }

    /**
     * The number this device should send next.
     */
    public function next(Device $device): int
    {
        return ((int) SyncOperation::query()->where('device_id', $device->getKey())->max('sequence')) + 1;
    }

    private function refuse(string $code, string $message, int $expected): never
    {
        throw new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
            'expected_sequence' => $expected,
        ], 409));
    }
}
