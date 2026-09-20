<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Domain\Identity\Models\Outlet;
use App\Domain\Sync\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * The terminal this browser is, as a row rather than a session value.
 *
 * A device has to outlive the session. A tablet logged out overnight, or one
 * that loses its session while the line is down, must come back as the same
 * terminal: its bill numbers carry a device prefix, and a terminal that
 * forgets who it is starts handing out numbers another terminal already used.
 */
class DeviceRegistry
{
    /**
     * A cookie, not local storage: it arrives with the first request, so a
     * terminal is itself before any script has had the chance to run.
     */
    public const COOKIE = 'device';

    /** Ten years. The row is the record; the cookie only has to point at it. */
    private const LIFETIME_MINUTES = 5_256_000;

    public function __construct(private readonly Request $request) {}

    /**
     * This terminal's device row at the given outlet, registering it on first
     * sight and marking it seen on every later one.
     */
    public function current(Outlet $outlet): Device
    {
        $device = Device::query()->firstOrNew(['fingerprint' => $this->fingerprint()]);

        // A tablet carried to another outlet stays one device and moves with
        // it, rather than leaving a second row behind to go stale.
        $device->forceFill([
            'outlet_id' => $outlet->getKey(),
            'name' => $device->name ?? __('sync.device.unnamed'),
            'platform' => Str::limit((string) $this->request->userAgent(), 250),
            'last_seen_at' => now(),
        ])->save();

        return $device;
    }

    /**
     * The id this browser carries, minted once and kept from then on.
     */
    private function fingerprint(): string
    {
        $carried = $this->request->cookie(self::COOKIE);

        if (is_string($carried) && $carried !== '') {
            return $carried;
        }

        $fingerprint = (string) Str::ulid();

        // Put it on the request too: one request may mint several numbers, and
        // a queued cookie is not readable until the browser sends it back.
        $this->request->cookies->set(self::COOKIE, $fingerprint);
        Cookie::queue(self::COOKIE, $fingerprint, self::LIFETIME_MINUTES);

        return $fingerprint;
    }
}
