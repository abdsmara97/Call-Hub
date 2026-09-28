<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops an X-Socket-ID header that is not a real socket id.
 *
 * Livewire puts `window.Echo.socketId()` on every component request. Until the
 * websocket handshake finishes — and again after any drop, and always when
 * Reverb is not running — that call returns undefined, which reaches us as the
 * literal string "undefined". Laravel reads the header for `->toOthers()` and
 * passes it straight to Pusher, which rejects anything that is not "\d+.\d+".
 * The result is a 500 on an ordinary action like sending a message.
 *
 * Without a socket id `->toOthers()` simply broadcasts to everyone, including
 * the sender — which is the correct outcome here, because a client with no live
 * socket is not listening and has nothing to be echoed back to it.
 */
class SanitiseSocketId
{
    public function handle(Request $request, Closure $next): Response
    {
        $socketId = $request->header('X-Socket-ID');

        // Same shape Pusher validates against; better to agree with it here
        // than to discover the disagreement as an exception mid-broadcast.
        if ($socketId !== null && ! preg_match('/\A\d+\.\d+\z/', $socketId)) {
            $request->headers->remove('X-Socket-ID');
        }

        return $next($request);
    }
}
