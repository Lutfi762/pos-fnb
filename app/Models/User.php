<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Domain\Identity\Models\OutletUser;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'pin_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Outlet, $this, OutletUser>
     */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class)
            ->using(OutletUser::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The outlet the user was last working in, used to pick a default.
     *
     * @return BelongsTo<Outlet, $this>
     */
    public function lastOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'last_outlet_id');
    }

    /**
     * Role held at one outlet, or null when not assigned there.
     * Requires the outlets relation to be loaded.
     */
    public function roleAt(Outlet|string $outlet): ?Role
    {
        $outletId = $outlet instanceof Outlet ? $outlet->getKey() : $outlet;

        $match = $this->outlets->firstWhere('id', $outletId);

        return $match instanceof Outlet
            ? $this->pivotRole($match)
            : null;
    }

    /**
     * Owners reach every outlet; everyone else only the ones assigned to them.
     */
    public function canAccessOutlet(Outlet|string $outlet): bool
    {
        $outletId = $outlet instanceof Outlet ? $outlet->getKey() : $outlet;

        return $this->outlets->contains(
            fn (Outlet $each): bool => $each->getKey() === $outletId
                || $this->pivotRole($each)->seesAllOutlets(),
        );
    }

    /**
     * Role held on a loaded outlet row, read off its pivot.
     */
    private function pivotRole(Outlet $outlet): Role
    {
        /** @var OutletUser $pivot */
        $pivot = $outlet->getRelation('pivot');

        return $pivot->role;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
