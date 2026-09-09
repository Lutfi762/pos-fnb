<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\CurrentOutlet;
use App\Domain\Identity\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on the role held at the *current* outlet. Someone can be a
 * manager in Kemang and a cashier in Senopati, so the outlet decides.
 */
class EnsureRole
{
    public function __construct(private readonly CurrentOutlet $current) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $held = $this->current->roleFor($user);

        abort_if($held === null, 403);

        $allowed = array_map(
            fn (string $role): Role => Role::from($role),
            $roles,
        );

        abort_unless(in_array($held, $allowed, strict: true), 403);

        return $next($request);
    }
}
