<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-issued accounts start with a temporary password. Until it is rotated the
 * user can reach nothing but the rotation screen and logout.
 */
class EnsurePasswordIsRotated
{
    /** Routes that must stay reachable while the password is still temporary. */
    private const ALLOWED = [
        'password.rotate',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs(self::ALLOWED)) {
            // Livewire's XHRs must be redirected too, or the user would sit on a
            // dead screen making requests that silently do nothing.
            return redirect()->route('password.rotate');
        }

        return $next($request);
    }
}
