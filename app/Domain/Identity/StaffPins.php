<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Six digit PINs: what unlocks a shared terminal and what signs off a
 * discount, a refund, or a short drawer.
 *
 * A supervisor is found by PIN alone, so two people who could stand at the
 * same till must never share one. "Could stand at the same till" means
 * sharing an outlet, and owners stand at every till.
 */
class StaffPins
{
    public const LENGTH = 6;

    /**
     * Refuse a PIN that is badly formed, trivially guessed, or already held
     * by someone this person works alongside.
     *
     * @param  list<string>  $outletIds  where the person works after this change
     *
     * @throws ValidationException
     */
    public function assertUsable(string $pin, ?User $holder, array $outletIds, bool $isOwner, string $field = 'pin'): void
    {
        if (preg_match('/^\d{'.self::LENGTH.'}$/', $pin) !== 1) {
            throw ValidationException::withMessages([$field => __('staff.pin_format', ['length' => self::LENGTH])]);
        }

        if (self::guessable($pin)) {
            throw ValidationException::withMessages([$field => __('staff.pin_guessable')]);
        }

        $taken = $this->colleagues($holder, $outletIds, $isOwner)
            ->contains(fn (User $colleague): bool => $colleague->verifyPin($pin));

        if ($taken) {
            throw ValidationException::withMessages([$field => __('staff.pin_taken')]);
        }
    }

    /**
     * Change a person's PIN after the same checks a manager's form gets.
     *
     * @throws ValidationException
     */
    public function change(User $user, string $pin, string $field = 'pin'): void
    {
        $user->loadMissing('outlets');

        $this->assertUsable(
            $pin,
            $user,
            array_values(array_map(strval(...), $user->outlets->modelKeys())),
            $user->isOwner(),
            $field,
        );

        $user->forceFill(['pin_hash' => $pin])->save();
    }

    /**
     * Same digits all the way, or a straight run up or down.
     */
    public static function guessable(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }

    /**
     * Everyone with a PIN who shares a till with this person: the staff of
     * the given outlets plus every owner. An owner is checked against all.
     *
     * @param  list<string>  $outletIds
     * @return Collection<int, User>
     */
    private function colleagues(?User $holder, array $outletIds, bool $isOwner): Collection
    {
        return User::query()
            ->whereNotNull('pin_hash')
            ->when($holder !== null, fn ($query) => $query->whereKeyNot($holder?->getKey()))
            ->when(! $isOwner, fn ($query) => $query->whereHas('outlets', fn ($outlets) => $outlets
                ->where(fn ($either) => $either
                    ->whereIn('outlets.id', $outletIds)
                    ->orWhere('outlet_user.role', Role::Owner->value))))
            ->get();
    }
}
