<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Who may sign off a discount, a refund, or a drawer that does not add up.
 *
 * A supervisor is anyone managing this outlet: its managers, and the owners,
 * who manage every outlet. The PIN is the whole check, so wrong guesses are
 * limited per outlet: five tries, then a five minute wait.
 */
class Supervisors
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 300;

    /**
     * The supervisor whose PIN this is, or null.
     */
    public function approve(Outlet $outlet, string $pin): ?User
    {
        $key = 'supervisor-pin:'.$outlet->getKey();

        if ($pin === '' || RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return null;
        }

        $supervisor = User::query()
            ->where('is_active', true)
            ->whereHas('outlets', fn ($query) => $query->whereIn('role', [Role::Owner->value, Role::Manager->value])
                ->where(fn ($query) => $query->where('outlets.id', $outlet->getKey())->orWhere('role', Role::Owner->value)))
            ->get()
            ->first(fn (User $user): bool => $user->verifyPin($pin));

        if ($supervisor === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return null;
        }

        RateLimiter::clear($key);

        return $supervisor;
    }

    public function lockedOut(Outlet $outlet): bool
    {
        return RateLimiter::tooManyAttempts('supervisor-pin:'.$outlet->getKey(), self::MAX_ATTEMPTS);
    }
}
