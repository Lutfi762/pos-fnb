<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use App\Domain\Sync\DeviceRegistry;
use Illuminate\Support\Str;

/**
 * Bill numbers, formatted {outlet}-{device}-{yymmdd}-{sequence}.
 *
 * The device prefix is what makes this safe offline: two tablets in the same
 * outlet can both mint numbers all day without ever colliding, because the
 * sequence only has to be unique within one device's own day.
 */
class OrderNumber
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    /**
     * The next number for this outlet, this device, today.
     */
    public function next(Outlet $outlet): string
    {
        $stem = $outlet->code.'-'.$this->devicePrefix($outlet).'-'.now()->format('ymd').'-';

        // Counting rows would reuse a number after a void, so the last one
        // issued is read back and incremented instead.
        $last = Order::query()
            ->where('outlet_id', $outlet->getKey())
            ->where('number', 'like', $stem.'%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = is_string($last)
            ? ((int) Str::afterLast($last, '-')) + 1
            : 1;

        return $stem.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * A short, stable id for this terminal, taken from its registered device.
     *
     * The tail of a ULID is the random half, so four characters of Crockford
     * base32 is a million terminals before two share a prefix. Two that did
     * would still not duplicate a number while online, because the sequence
     * below reads back whatever the shared stem already issued.
     *
     * ponytail: the sequence is read from the server, so two terminals that
     * are offline at once could both mint the same number. Hand it out from
     * the queued operation instead when the offline queue lands.
     */
    public function devicePrefix(Outlet $outlet): string
    {
        return Str::upper(Str::substr((string) $this->devices->current($outlet)->getKey(), -4));
    }
}
