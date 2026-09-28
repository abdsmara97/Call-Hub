<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Events\HuddleUpdated;
use App\Exceptions\HuddleUnavailable;
use App\Models\Room;
use App\Models\User;
use App\Services\HuddleRegistry;
use App\Services\HuddleTokens;
use App\Services\LiveKitApi;
use Illuminate\Console\Command;

/**
 * Makes the cached huddle state agree with LiveKit, and enforces the rules the
 * request lifecycle can no longer reach.
 *
 * Two jobs, and the second is the one that must not be skipped.
 *
 * The first is reconciliation. Webhooks are the fast path and they are lossy: a
 * dropped participant_left leaves a phantom "Call in progress" banner, a dropped
 * participant_joined leaves someone invisible, and a cache flush loses the lot.
 * LiveKit itself is the authority, so once a minute we ask it and rewrite what
 * we believe. This is the only place allowed to rewrite the live index outright.
 *
 * The second is enforcement. Once somebody is inside a huddle there is no next
 * HTTP request, so EnsureAccountIsActive never runs again and RoomPolicy is
 * never consulted again. A suspended account, or somebody removed from a private
 * room, would otherwise stay in the meeting indefinitely. That check has nowhere
 * else to live but here.
 */
class ReconcileHuddles extends Command
{
    protected $signature = 'huddles:reconcile';

    protected $description = 'Rebuild huddle state from LiveKit and eject participants who may no longer be there';

    public function handle(HuddleRegistry $registry, LiveKitApi $livekit, HuddleTokens $tokens): int
    {
        if (! $tokens->isConfigured()) {
            return self::SUCCESS;
        }

        try {
            $rooms = $livekit->listRooms();
        } catch (HuddleUnavailable $e) {
            // Leave the cache alone. Stale state that heals next minute beats
            // clearing every banner in the hub because the SFU blipped.
            report($e);

            return self::FAILURE;
        }

        $live = [];
        $ejected = 0;

        foreach ($rooms as $room) {
            $name = $room['name'] ?? null;
            $roomId = is_string($name) ? $tokens->roomIdFrom($name) : null;

            if ($roomId === null) {
                continue;
            }

            /*
             * A forgotten tab left connected overnight is billable egress and a
             * banner nobody can clear. empty_timeout does not help — it only
             * fires when a room is EMPTY, and this one is not.
             */
            if ($this->isExpired($room)) {
                $livekit->deleteRoom($name);
                $registry->markFinished($roomId);
                $this->line("Ended huddle in room {$roomId}: past the maximum duration.");

                continue;
            }

            try {
                $participants = $livekit->listParticipants($name);
            } catch (HuddleUnavailable $e) {
                report($e);
                // Keep believing what we already believed about this one room.
                $live[] = $roomId;

                continue;
            }

            $ejected += $this->enforce($livekit, $registry, $tokens, $name, $roomId, $participants);

            // Re-read after any ejection so the snapshot is not immediately stale.
            $participants = $ejected > 0 ? $livekit->listParticipants($name) : $participants;

            $shaped = array_values(array_filter(array_map(
                fn ($p) => $registry->participantFrom($p),
                $participants,
            )));

            if ($shaped !== []) {
                $live[] = $roomId;
            }

            if ($registry->replace($roomId, $shaped)) {
                // Only when the roster actually moved. Broadcasting an unchanged
                // snapshot every minute to every member of every room with a
                // live huddle would be a lot of socket traffic saying nothing.
                HuddleUpdated::dispatch($roomId, $registry->snapshot($roomId));
            }
        }

        // Anything we believed was live and LiveKit no longer has: the
        // phantom-banner case, and the reason this runs every minute.
        foreach (array_diff($registry->liveRoomIds(), $live) as $stale) {
            $registry->markFinished($stale);
            $this->line("Cleared stale huddle state for room {$stale}.");
        }

        $registry->replaceIndex($live);

        $this->info(sprintf(
            'Reconciled %d live huddle(s); ejected %d participant(s).',
            count($live),
            $ejected,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function isExpired(array $room): bool
    {
        // protojson: int64 arrives as a JSON string.
        $created = (int) ($room['creationTime'] ?? 0);

        if ($created <= 0) {
            return false;
        }

        $maxSeconds = (int) config('hub.huddles.max_duration_minutes') * 60;

        return (time() - $created) > $maxSeconds;
    }

    /**
     * Eject anyone who should no longer be in this huddle.
     *
     * One query for the whole room rather than one per participant, because a
     * company-wide huddle is thirty of them and this runs every minute.
     *
     * @param  list<array<string, mixed>>  $participants
     */
    private function enforce(
        LiveKitApi $livekit,
        HuddleRegistry $registry,
        HuddleTokens $tokens,
        string $name,
        int $roomId,
        array $participants,
    ): int {
        if ($participants === []) {
            return 0;
        }

        $byUserId = [];

        foreach ($participants as $participant) {
            $identity = $participant['identity'] ?? null;
            $userId = is_string($identity) ? $tokens->userIdFrom($identity) : null;

            if ($userId !== null) {
                $byUserId[$userId] = $identity;
            }
        }

        if ($byUserId === []) {
            return 0;
        }

        $room = Room::find($roomId);

        // The Room was deleted while a huddle was running in it.
        if ($room === null) {
            $livekit->deleteRoom($name);
            $registry->markFinished($roomId);

            return 0;
        }

        $userIds = array_keys($byUserId);

        // Still active accounts...
        $active = User::query()
            ->whereIn('id', $userIds)
            ->where('status', UserStatus::Active->value)
            ->pluck('id')
            ->all();

        // ...that are still members of this room.
        $members = $room->members()
            ->whereIn('users.id', $active)
            ->pluck('users.id')
            ->all();

        $allowed = array_flip($members);
        $ejected = 0;

        foreach ($byUserId as $userId => $identity) {
            if (isset($allowed[$userId])) {
                continue;
            }

            $livekit->removeParticipant($name, $identity);
            $registry->markLeft($roomId, $identity);
            $ejected++;

            $this->line("Removed {$identity} from room {$roomId}: suspended, or no longer a member.");
        }

        return $ejected;
    }
}
