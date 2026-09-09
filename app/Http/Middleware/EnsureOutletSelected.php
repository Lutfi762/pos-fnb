<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Models\Outlet;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every screen behind this works on exactly one outlet. Pick it once,
 * on login, and re-check it on each request in case access was revoked.
 */
class EnsureOutletSelected
{
    public function __construct(private readonly CurrentOutlet $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $outlet = $this->current->get();

        if ($outlet instanceof Outlet && $outlet->is_active && $user->canAccessOutlet($outlet)) {
            return $next($request);
        }

        $this->current->forget();

        // One outlet and no choice to make: skip the picker entirely.
        $options = $user->accessibleOutlets();

        if ($options->count() === 1) {
            $this->current->set($options->firstOrFail(), $user);

            return $next($request);
        }

        return redirect()->guest(route('outlet.select'));
    }
}
