<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sync\Models\Device;
use App\Domain\Sync\Models\SyncOperation;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Who sent a queued command, from which terminal, and when it happened there.
 */
final readonly class SyncContext
{
    public function __construct(
        public Outlet $outlet,
        public User $user,
        public Device $device,
        public SyncOperation $operation,
    ) {}

    /**
     * The moment the command was recorded on the device.
     *
     * A device clock can drift, but it cannot be in the future of the server:
     * a sale "made tomorrow" would land in tomorrow's report, so it is capped.
     */
    public function happenedAt(): CarbonImmutable
    {
        $at = CarbonImmutable::parse($this->operation->client_created_at)->setTimezone(config('app.timezone'));

        return $at->greaterThan(now()) ? CarbonImmutable::now() : $at;
    }
}
