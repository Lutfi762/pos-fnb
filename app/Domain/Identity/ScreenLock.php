<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use Illuminate\Contracts\Session\Session;

/**
 * Shared-terminal lock. Locking keeps the outlet chosen and the session
 * alive, it only hides the screen until someone types a valid PIN.
 */
class ScreenLock
{
    public const SESSION_KEY = 'screen_locked';

    public function __construct(private readonly Session $session) {}

    public function isLocked(): bool
    {
        return $this->session->get(self::SESSION_KEY) === true;
    }

    public function lock(): void
    {
        $this->session->put(self::SESSION_KEY, true);
    }

    public function unlock(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
