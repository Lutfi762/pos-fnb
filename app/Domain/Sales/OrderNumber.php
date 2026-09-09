<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sales\Models\Order;
use Illuminate\Contracts\Session\Session;
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
    public const SESSION_KEY = 'device_prefix';

    public function __construct(private readonly Session $session) {}

    /**
     * The next number for this outlet, this device, today.
     */
    public function next(Outlet $outlet): string
    {
        $stem = $outlet->code.'-'.$this->devicePrefix().'-'.now()->format('ymd').'-';

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
     * A short, stable id for this terminal, kept in the session.
     *
     * ponytail: session-scoped, so clearing browser data mints a new prefix.
     * Move it to a registered device row when the sync step lands.
     */
    public function devicePrefix(): string
    {
        $prefix = $this->session->get(self::SESSION_KEY);

        if (! is_string($prefix)) {
            $prefix = Str::upper(Str::random(3));
            $this->session->put(self::SESSION_KEY, $prefix);
        }

        return $prefix;
    }
}
