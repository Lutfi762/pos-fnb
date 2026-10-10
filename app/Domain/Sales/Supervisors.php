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
 *
 * Two supervisors with the same PIN cannot be told apart, so a PIN that fits
 * more than one of them approves nothing: the record would name the wrong
 * person. Staff management refuses such PINs; this guards older data.
 */
class Supervisors
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 300;

    private bool $ambiguous = false;

    /**
     * The supervisor whose PIN this is, or null.
     */
    public function approve(Outlet $outlet, string $pin): ?User
    {
        $key = 'supervisor-pin:'.$outlet->getKey();
        $this->ambiguous = false;

        if ($pin === '' || RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return null;
        }

        $matches = User::query()
            ->where('is_active', true)
            ->whereHas('outlets', fn ($query) => $query->whereIn('role', [Role::Owner->value, Role::Manager->value])
                ->where(fn ($query) => $query->where('outlets.id', $outlet->getKey())->orWhere('role', Role::Owner->value)))
            ->get()
            ->filter(fn (User $user): bool => $user->verifyPin($pin));

        if ($matches->count() > 1) {
            $this->ambiguous = true;

            return null;
        }

        $supervisor = $matches->first();

        if ($supervisor === null) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return null;
        }

        RateLimiter::clear($key);

        return $supervisor;
    }

    /**
     * Whether the last refusal was a PIN shared by several supervisors,
     * rather than a wrong one.
     */
    public function wasAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    /**
     * The message for the last refusal, in the caller's own words for a
     * plain wrong PIN.
     */
    public function refusal(string $wrongPin): string
    {
        return $this->ambiguous ? __('staff.pin_ambiguous') : $wrongPin;
    }

    public function lockedOut(Outlet $outlet): bool
    {
        return RateLimiter::tooManyAttempts('supervisor-pin:'.$outlet->getKey(), self::MAX_ATTEMPTS);
    }
}
