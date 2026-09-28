<?php

namespace App\Http\Controllers;

use App\Services\HuddleRegistry;
use App\Services\HuddleTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What LiveKit tells us about huddles as they happen.
 *
 * This is the only reason the join banner can be live without polling: LiveKit
 * pushes here, we update the cache and broadcast on the room's existing private
 * channel, and every member's banner moves.
 *
 * The governing rule for the whole class is that **almost everything answers
 * 200**. LiveKit retries any non-2xx indefinitely, so a 4xx for an event we
 * simply do not care about would turn one unknown event type into a permanent
 * retry loop against this endpoint. The only 401 is a failed signature, and that
 * is decided in VerifyLiveKitWebhook before we get here.
 *
 * Nothing in this method touches the database. Names and avatars arrive in the
 * token metadata we signed at join time, which is what lets a company-wide join
 * storm cost a cache read and a cache write per participant instead of a query.
 */
class LiveKitWebhookController extends Controller
{
    public function __invoke(Request $request, HuddleRegistry $registry, HuddleTokens $tokens): JsonResponse
    {
        $event = $request->json()->all();
        $type = $event['event'] ?? null;

        // A retried participant_left must not be processed twice.
        $id = $event['id'] ?? null;

        if (is_string($id) && $id !== '' && $registry->isDuplicate($id)) {
            return $this->ok();
        }

        $name = $event['room']['name'] ?? null;
        $roomId = is_string($name) ? $tokens->roomIdFrom($name) : null;

        // Not one of ours, or a Room that has since been deleted. Answer 200 and
        // drop it: a 4xx here would have LiveKit retrying forever.
        if ($roomId === null) {
            return $this->ok();
        }

        match ($type) {
            'room_started' => $registry->markStarted(
                $roomId,
                // int64 arrives as a JSON string in protojson.
                isset($event['room']['creationTime'])
                    ? (int) $event['room']['creationTime']
                    : null,
            ),
            'participant_joined' => $this->joined($registry, $roomId, $event),
            'participant_left' => $this->left($registry, $roomId, $event),
            'room_finished' => $registry->markFinished($roomId),
            // track_published, egress_*, and whatever a future LiveKit adds.
            default => null,
        };

        return $this->ok();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function joined(HuddleRegistry $registry, int $roomId, array $event): void
    {
        $participant = $registry->participantFrom($event['participant'] ?? []);

        if ($participant !== null) {
            $registry->markJoined($roomId, $participant);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function left(HuddleRegistry $registry, int $roomId, array $event): void
    {
        $identity = $event['participant']['identity'] ?? null;

        if (is_string($identity) && $identity !== '') {
            // The sid is what makes a stale departure inert — see markLeft().
            $registry->markLeft($roomId, $identity, $event['participant']['sid'] ?? null);
        }
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
