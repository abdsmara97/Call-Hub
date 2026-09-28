<?php

namespace App\Http\Controllers;

use App\Exceptions\CallCredentialsUnavailable;
use App\Exceptions\HuddleUnavailable;
use App\Models\Room;
use App\Services\CallCredentials;
use App\Services\HuddleTokens;
use App\Services\LiveKitApi;
use App\Support\HubSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The one moment of a huddle that needs the server: minting a join token.
 *
 * There is deliberately no leave endpoint. LiveKit notices a departure itself
 * and tells us over the webhook, and a beacon the browser might not send is
 * worse than no beacon at all — it would be a second source of truth that is
 * wrong precisely when it matters, on a crashed tab.
 *
 * POST for the same reasons as CallCredentialController: nothing here is
 * cacheable, minting a credential is an action rather than a resource read, and
 * this response is the only path by which a signed token reaches a browser.
 */
class HuddleTokenController extends Controller
{
    public function __invoke(
        Request $request,
        Room $room,
        HuddleTokens $tokens,
        LiveKitApi $livekit,
        CallCredentials $credentials,
        HubSettings $settings,
    ): JsonResponse {
        // Second gate. The policy is where membership, the kill switch and the
        // "is LiveKit even configured" question are answered — not the template
        // that hides the button.
        Gate::authorize('huddle', $room);

        $user = $request->user();
        $name = $tokens->roomNameFor($room);
        $cap = $settings->huddleMaxParticipants();

        try {
            /*
             * Always authoritative, never the cache.
             *
             * Reads elsewhere in this feature may be stale — a lost webhook can
             * leave a phantom banner for up to a minute. The cap check never is,
             * because a cold cache must not be able to let a thirty-first person
             * into a thirty-person huddle.
             */
            $live = $livekit->listParticipants($name);

            /*
             * The second half of this condition is the important one. Somebody
             * whose network blipped has to be able to reconnect into a huddle
             * that is at cap — locking them out of the meeting they were already
             * in would be the worst behaviour available to us.
             */
            $identity = $tokens->identityFor($user);
            $alreadyIn = collect($live)->contains(fn ($p) => ($p['identity'] ?? null) === $identity);

            if (count($live) >= $cap && ! $alreadyIn) {
                // 409 rather than 403, following CallSignalController: they are
                // allowed to huddle here, just not right now. The distinction
                // decides what the client offers next.
                return response()->json([
                    'reason' => 'full',
                    'message' => "This huddle is full ({$cap} people).",
                ], 409);
            }

            // Idempotent, and the server-side cap. Two people at cap-minus-one
            // can both pass the check above; this is what refuses the second.
            $livekit->createRoom($name, $cap, (int) config('hub.huddles.empty_timeout_seconds'));
        } catch (HuddleUnavailable $e) {
            // Reported, not returned — the failing request carried our secret.
            report($e);

            return response()->json(['message' => 'Huddles are temporarily unavailable.'], 503);
        }

        $token = $tokens->forParticipant($user, $room, Gate::allows('moderate', $room));

        /*
         * A relay for the minority of participants whose network will not pass
         * media directly. Reusing the TURN this hub already pays for rather than
         * standing up LiveKit's own: the client allocates on Cloudflare and the
         * SFU sends to the relay address, which works over TCP/TLS on 443 —
         * exactly the corporate-firewall case CallCredentials was written for.
         *
         * This must never fail the join. A huddle without a relay works for most
         * people; no token works for nobody.
         */
        $iceServers = [];

        try {
            $iceServers = $credentials->mint();
        } catch (CallCredentialsUnavailable $e) {
            report($e);
        }

        return response()
            ->json([
                'url' => $tokens->url(),
                'token' => $token,
                'room_name' => $name,
                'identity' => $tokens->identityFor($user),
                'expires_in' => $tokens->ttl(),
                'max_participants' => $cap,
                'ice_servers' => $iceServers,
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
