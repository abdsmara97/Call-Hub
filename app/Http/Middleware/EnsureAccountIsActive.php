<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account suspended mid-session must lose it on the next request, not at the
 * next login.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Explicitly the web guard: these rules police hub accounts. On a
         * platform-guard request $request->user() would be the operator,
         * who has none of these lifecycle rules.
         */
        $user = $request->user('web');

        if ($user && ! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['form.email' => __('This account has been suspended. Contact your administrator.')]);
        }

        return $next($request);
    }
}
