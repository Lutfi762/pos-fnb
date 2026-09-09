<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The outlet the signed in user is currently working in.
 *
 * Kept in the session rather than the URL: a cashier never switches outlet
 * mid-shift, and leaving it out of every route keeps links short.
 */
class CurrentOutlet
{
    public const SESSION_KEY = 'current_outlet_id';

    private ?Outlet $outlet = null;

    public function __construct(private readonly Session $session) {}

    public function id(): ?string
    {
        $id = $this->session->get(self::SESSION_KEY);

        return is_string($id) ? $id : null;
    }

    public function isSet(): bool
    {
        return $this->id() !== null;
    }

    /**
     * The resolved outlet, or null when none has been picked yet.
     */
    public function get(): ?Outlet
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->outlet?->getKey() !== $id) {
            $this->outlet = Outlet::query()->find($id);
        }

        return $this->outlet;
    }

    /**
     * The resolved outlet, failing loudly when code assumed one was picked.
     */
    public function getOrFail(): Outlet
    {
        $outlet = $this->get();

        if (! $outlet instanceof Outlet) {
            throw new \RuntimeException('No outlet selected for the current session.');
        }

        return $outlet;
    }

    /**
     * Remember the outlet for this session and as the user's default next login.
     */
    public function set(Outlet $outlet, ?User $user = null): void
    {
        $this->outlet = $outlet;
        $this->session->put(self::SESSION_KEY, $outlet->getKey());

        $user?->forceFill(['last_outlet_id' => $outlet->getKey()])->save();
    }

    public function forget(): void
    {
        $this->outlet = null;
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * Role the given user holds at the current outlet.
     */
    public function roleFor(User $user): ?Role
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        $user->loadMissing('outlets');

        // An owner keeps their role at outlets opened after they were hired.
        return $user->roleAt($id) ?? ($user->isOwner() ? Role::Owner : null);
    }
}
