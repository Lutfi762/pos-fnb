<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Audit\Audit;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add a person to an outlet, or change who they are there.
 *
 * Owners hire anyone, anywhere. A manager hires the floor of their own outlet:
 * cashiers, kitchen, and warehouse staff, never another manager or an owner,
 * and never touches anyone above that line. Nobody edits themselves here;
 * their own password and PIN live under Settings.
 */
class SaveStaff
{
    /** Roles a manager may hand out. */
    public const FLOOR = [Role::Cashier, Role::Kitchen, Role::Warehouse];

    public function __construct(
        private readonly StaffPins $pins,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, role: string, password?: string|null, pin?: string|null, outlets?: list<string>}  $input
     *
     * @throws ValidationException
     */
    public function handle(User $by, Outlet $outlet, array $input, ?User $staff = null): User
    {
        $byRole = self::roleOf($by, $outlet);
        $role = Role::from($input['role']);

        self::authorize($by, $byRole, $outlet, $staff);
        abort_unless($byRole === Role::Owner || in_array($role, self::FLOOR, true), 403);

        $password = ($input['password'] ?? '') === '' ? null : $input['password'];
        $pin = ($input['pin'] ?? '') === '' ? null : $input['pin'];

        abort_if($staff === null && ($password === null || $pin === null), 422, __('staff.secrets_required'));

        $outletIds = $this->outletsFor($byRole, $outlet, $input['outlets'] ?? []);

        if ($pin !== null) {
            $this->pins->assertUsable($pin, $staff, $outletIds, $role === Role::Owner);
        }

        $before = $staff === null ? null : self::snapshot($staff, $outlet);

        $saved = DB::transaction(function () use ($staff, $input, $password, $pin, $byRole, $outlet, $role, $outletIds): User {
            $user = $staff ?? new User;

            $user->fill(['name' => $input['name'], 'email' => mb_strtolower(trim($input['email']))]);

            if ($password !== null) {
                $user->password = $password;
            }

            if ($pin !== null) {
                $user->forceFill(['pin_hash' => $pin]);
            }

            if (! $user->exists) {
                // Staff are vouched for by whoever hired them; there is no
                // inbox to confirm at a till.
                $user->forceFill(['is_active' => true, 'email_verified_at' => now(), 'last_outlet_id' => $outlet->getKey()]);
            }

            $user->save();

            $assignments = array_fill_keys($outletIds, ['role' => $role->value]);

            // A manager only speaks for their own outlet; an owner sets the
            // whole list.
            if ($byRole === Role::Owner) {
                $user->outlets()->sync($assignments);
            } else {
                $user->outlets()->syncWithoutDetaching($assignments);
            }

            return $user;
        });

        $saved->unsetRelation('outlets');

        $this->audit->record(
            $staff === null ? 'staff.created' : 'staff.updated',
            $saved,
            $before,
            self::snapshot($saved, $outlet) + ['password_changed' => $password !== null, 'pin_changed' => $pin !== null],
            user: $by,
            outletId: $outlet->getKey(),
        );

        return $saved;
    }

    /**
     * Switch an account on or off. Off also ends the session it already has.
     */
    public function setActive(User $by, Outlet $outlet, User $staff, bool $active): User
    {
        self::authorize($by, self::roleOf($by, $outlet), $outlet, $staff);

        $staff->forceFill(['is_active' => $active])->save();

        $this->audit->record($active ? 'staff.activated' : 'staff.deactivated', $staff, user: $by, outletId: $outlet->getKey());

        return $staff;
    }

    /**
     * Whether this person may edit that one at this outlet.
     */
    public static function canManage(User $by, Outlet $outlet, User $staff): bool
    {
        $byRole = self::roleOf($by, $outlet);

        if ($byRole === null || ! $byRole->managesOutlet() || $staff->is($by)) {
            return false;
        }

        return $byRole === Role::Owner
            || (! $staff->isOwner() && in_array($staff->roleAt($outlet), self::FLOOR, true));
    }

    /**
     * Roles this person may hand out.
     *
     * @return list<Role>
     */
    public static function assignable(User $by, Outlet $outlet): array
    {
        return self::roleOf($by, $outlet) === Role::Owner ? Role::cases() : self::FLOOR;
    }

    public static function roleOf(User $user, Outlet $outlet): ?Role
    {
        return $user->isOwner() ? Role::Owner : $user->roleAt($outlet);
    }

    private static function authorize(User $by, ?Role $byRole, Outlet $outlet, ?User $staff): void
    {
        abort_unless($byRole?->managesOutlet() === true, 403);

        if ($staff !== null) {
            abort_unless(self::canManage($by, $outlet, $staff), 403);
        }
    }

    /**
     * Where the person works after saving. A manager can only place someone
     * at their own outlet; an owner picks from the active outlets, and an
     * owner account belongs to all of them anyway.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function outletsFor(Role $byRole, Outlet $outlet, array $requested): array
    {
        if ($byRole !== Role::Owner) {
            return [$outlet->getKey()];
        }

        $ids = Outlet::query()->where('is_active', true)->whereKey($requested === [] ? [$outlet->getKey()] : $requested)->pluck('id')->all();

        if ($ids === []) {
            throw ValidationException::withMessages(['outlets' => __('staff.outlets_required')]);
        }

        return array_values(array_map('strval', $ids));
    }

    /**
     * What an audit reader needs, never a secret.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(User $user, Outlet $outlet): array
    {
        $user->loadMissing('outlets');

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->roleAt($outlet)?->value,
            'outlets' => $user->outlets->modelKeys(),
        ];
    }
}
