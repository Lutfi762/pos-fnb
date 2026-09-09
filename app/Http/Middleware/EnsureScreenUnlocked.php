<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\ScreenLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A POS terminal is shared. When it is locked, nothing but the PIN pad
 * is reachable, even though a session is still signed in.
 */
class EnsureScreenUnlocked
{
    public function __construct(private readonly ScreenLock $lock) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->lock->isLocked()) {
            return redirect()->guest(route('lock'));
        }

        return $next($request);
    }
}
