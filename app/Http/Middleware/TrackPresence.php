<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cheap "last seen" tracking. Throttled to one write a minute so a chatty
 * Livewire session does not turn into a write storm.
 */
class TrackPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Explicitly the web guard: these rules police hub accounts. On a
         * platform-guard request $request->user() would be the operator,
         * who has none of these lifecycle rules.
         */
        $user = $request->user('web');

        if ($user && (! $user->last_seen_at || $user->last_seen_at->diffInSeconds(now()) > 60)) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
