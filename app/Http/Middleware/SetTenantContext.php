<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant boundary for the request from the authenticated user.
 * Runs after auth so guests simply leave the context unbound (the login
 * screen has no tenant until someone signs in).
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id !== null) {
            app(TenantContext::class)->set($user->tenant_id);
        }

        return $next($request);
    }
}
