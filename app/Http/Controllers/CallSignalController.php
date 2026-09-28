<?php

namespace App\Http\Controllers;

use App\Exceptions\CallUnavailable;
use App\Models\Room;
use App\Services\CallService;
use App\Support\HubSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The two moments of a call that need the server.
 *
 * Everything between them — accept, decline, SDP, ICE, hangup — travels as a
 * client whisper on the room's presence channel and never arrives here. The
 * server is involved only where a whisper cannot reach: before the callee has
 * joined the channel.
 */
class CallSignalController extends Controller
{
    public function ring(Request $request, Room $room, CallService $calls, HubSettings $settings): JsonResponse
    {
        // Second gate. Channel authorisation let them listen; it never let them
        // act, and the policy is where DM-only and 1:1-only are enforced.
        Gate::authorize('call', $room);

        $validated = $request->validate([
            'video' => ['sometimes', 'boolean'],
        ]);

        $callee = $room->otherMember($request->user());

        if (! $callee) {
            return response()->json(['message' => 'There is nobody else in this conversation.'], 409);
        }

        try {
            $call = $calls->ring(
                $request->user(),
                $room,
                $callee,
                (bool) ($validated['video'] ?? false),
            );
        } catch (CallUnavailable $e) {
            // 409 rather than 403: they are allowed to call this person, just
            // not right now. The distinction decides what the client offers next.
            return response()->json([
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'call_id' => $call['callId'],
            'quiet' => $call['quiet'],
            'ring_seconds' => $settings->callRingSeconds(),
        ]);
    }

    public function cancel(Request $request, Room $room, CallService $calls): JsonResponse
    {
        Gate::authorize('call', $room);

        $validated = $request->validate([
            'call_id' => ['required', 'string', 'uuid'],
        ]);

        $callee = $room->otherMember($request->user());

        if ($callee) {
            $calls->cancel($validated['call_id'], $callee);
        }

        return response()->json(['ok' => true]);
    }
}
