<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Audit\Audit;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;

/**
 * Set an outlet's timezone and the hour its trading day starts.
 *
 * Applies from the next transaction on. Days already traded keep the date
 * they were counted under; `meja:rebuild-summaries --redate` rewrites them
 * when the owner really wants history to follow.
 */
class UpdateOutletClock
{
    /** Indonesia's three zones, the only ones an outlet here can be in. */
    public const ZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];

    public const LATEST_START = 8;

    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly Audit $audit,
    ) {}

    public function handle(Outlet $outlet, string $timezone, int $dayStartsAt, User $by): Outlet
    {
        abort_unless(in_array($timezone, self::ZONES, true), 422, __('outlet.timezone_invalid'));
        abort_unless($dayStartsAt >= 0 && $dayStartsAt <= self::LATEST_START, 422, __('outlet.day_start_invalid'));

        $before = ['timezone' => $outlet->getAttribute('timezone'), 'day_starts_at' => $outlet->getAttribute('day_starts_at')];

        $outlet->update(['timezone' => $timezone, 'day_starts_at' => $dayStartsAt]);
        $this->calendar->forget($outlet);

        $this->audit->record('outlet.clock_changed', $outlet, $before, [
            'timezone' => $timezone,
            'day_starts_at' => $dayStartsAt,
        ], user: $by, outletId: $outlet->getKey());

        return $outlet;
    }
}
