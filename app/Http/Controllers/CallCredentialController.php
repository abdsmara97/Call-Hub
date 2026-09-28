<?php

namespace App\Http\Controllers;

use App\Exceptions\CallCredentialsUnavailable;
use App\Models\Room;
use App\Services\CallCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Hands the browser a short-lived ICE server list.
 *
 * POST rather than GET so nothing caches it, and so it carries CSRF by default.
 * The response is the only path by which a TURN credential reaches a client:
 * it is never rendered into the page, never written to storage, and never
 * given a VITE_ twin. A static TURN password in a front-end bundle is how
 * organisations end up paying for someone else's relay traffic.
 */
class CallCredentialController extends Controller
{
    public function __invoke(Room $room, CallCredentials $credentials): JsonResponse
    {
        Gate::authorize('call', $room);

        try {
            $iceServers = $credentials->mint();
        } catch (CallCredentialsUnavailable $e) {
            // Reported, not returned. The failing request carried our API token.
            report($e);

            return response()->json(['message' => 'Calling is temporarily unavailable.'], 503);
        }

        return response()
            ->json([
                'ice_servers' => $iceServers,
                'expires_in' => $credentials->ttl(),
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
