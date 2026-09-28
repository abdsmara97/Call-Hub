<?php

namespace App\Services;

use App\Enums\Availability;
use App\Enums\CallDisposition;
use App\Events\CallCancelled;
use App\Events\CallRinging;
use App\Exceptions\CallUnavailable;
use App\Models\Room;
use App\Models\User;
use App\Support\HubSettings;
use Illuminate\Support\Str;

/**
 * The ring lifecycle, and nothing else.
 *
 * This service writes no rows. A call leaves no trace by design: media is
 * peer-to-peer, so there is nothing to record even if we wanted to, and a
 * half-record of who rang whom would be an audit trail that cannot be trusted.
 * If a caller wants the attempt remembered, the client offers to send an
 * ordinary message — which lands in the durable medium the hub already has.
 */
class CallService
{
    public function __construct(private HubSettings $settings) {}

    /**
     * Decide whether the callee may be rung, and how loudly, then announce it.
     *
     * @return array{callId: string, quiet: bool}
     *
     * @throws CallUnavailable
     */
    public function ring(User $caller, Room $room, User $callee, bool $video = false): array
    {
        if (! $this->settings->callsEnabled()) {
            throw CallUnavailable::disabled();
        }

        $disposition = $this->dispositionFor($callee);

        if ($disposition === CallDisposition::Blocked) {
            throw $this->refusalFor($callee);
        }

        $callId = (string) Str::uuid();

        CallRinging::dispatch(
            $callId,
            $room,
            $caller,
            $callee,
            $disposition->isQuiet(),
            $video,
        );

        return ['callId' => $callId, 'quiet' => $disposition->isQuiet()];
    }

    /** Un-rings a callee who has not answered yet. */
    public function cancel(string $callId, User $callee): void
    {
        CallCancelled::dispatch($callId, $callee);
    }

    /**
     * Availability decides the volume, not the permission — with two exceptions.
     *
     * Off shift blocks outright because it is a roster fact rather than a mood,
     * and ringing someone who is not working is the behaviour this product
     * exists to avoid. "Away" rings at full volume on purpose: it is a passive,
     * often-stale inference, not a stated preference the way DND is.
     */
    public function dispositionFor(User $callee): CallDisposition
    {
        return match (true) {
            ! $callee->isActive() => CallDisposition::Blocked,
            $callee->availability === Availability::OffShift => CallDisposition::Blocked,
            $callee->availability === Availability::Busy => CallDisposition::Quiet,
            $callee->isWithinDndWindow() => CallDisposition::Quiet,
            default => CallDisposition::Ring,
        };
    }

    private function refusalFor(User $callee): CallUnavailable
    {
        return $callee->availability === Availability::OffShift && $callee->isActive()
            ? CallUnavailable::offShift($callee->name)
            : CallUnavailable::inactive($callee->name);
    }
}
