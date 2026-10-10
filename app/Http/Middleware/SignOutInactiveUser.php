<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account loses the session it already had, not only the next
 * login. Otherwise a cashier let go mid-shift keeps ringing up sales until
 * the browser is closed.
 */
class SignOutInactiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_unless($request->isMethod('GET') && ! $request->expectsJson(), 401);

        return redirect()->route('login')->with('status', __('staff.signed_out'));
    }
}
