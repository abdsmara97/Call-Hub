<?php

namespace App\Policies;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\User;
use App\Services\HuddleTokens;
use App\Support\HubSettings;
use App\Support\Permissions;

class RoomPolicy
{
    public function view(User $user, Room $room): bool
    {
        // Public rooms are readable by anyone; everything else needs membership.
        return $room->isPublic() || $user->belongsToRoom($room);
    }

    public function join(User $user, Room $room): bool
    {
        return $room->type === RoomType::Public && ! $user->belongsToRoom($room);
    }

    public function leave(User $user, Room $room): bool
    {
        // System rooms mirror the org chart — you cannot opt out of your own
        // company or department room.
        return ! $room->is_system && ! $room->isDm() && $user->belongsToRoom($room);
    }

    public function post(User $user, Room $room): bool
    {
        return $user->belongsToRoom($room);
    }

    public function moderate(User $user, Room $room): bool
    {
        return $user->can(Permissions::MANAGE_ROOMS) || $user->moderatesRoom($room);
    }

    public function invite(User $user, Room $room): bool
    {
        if ($room->isDm()) {
            return false;
        }

        return $this->moderate($user, $room) || ($room->isPublic() && $user->belongsToRoom($room));
    }

    /**
     * Calling is direct-message-only and strictly one-to-one, and that is
     * enforced here rather than by hiding a button — a hidden control is a
     * suggestion, a policy is a rule.
     *
     * The membership count is belt and braces. Group calling would need a
     * selective forwarding unit rather than the peer-to-peer mesh this uses, so
     * the day a direct message can hold three people, calls should fail closed
     * instead of half-working.
     */
    public function call(User $user, Room $room): bool
    {
        if (! app(HubSettings::class)->callsEnabled()) {
            return false;
        }

        return $room->isDm()
            && $user->belongsToRoom($room)
            && $room->members()->count() === 2;
    }

    /**
     * A huddle is not a call, and this is deliberately not call() above.
     *
     * Calling is one-to-one over a peer-to-peer mesh, which is exactly why it is
     * confined to a direct message with two members. A huddle goes through a
     * selective forwarding unit, so it has none of those constraints: any room
     * type, any size, including the system rooms nobody can leave. Copying the
     * direct-message guard across from call() would be the natural mistake, and
     * HuddleAuthorizationTest exists to catch it.
     *
     * Membership, and only membership. Note this is stricter than view(): a
     * public room is readable by anyone, but reading a room is not standing in
     * it, and a participant list is a presence disclosure.
     *
     * The configuration check is not belt and braces. With no LiveKit configured
     * the feature cannot work at all, and a policy that answers "no" is how a
     * fresh clone renders no huddle button rather than one that fails when
     * pressed.
     */
    public function huddle(User $user, Room $room): bool
    {
        if (! app(HubSettings::class)->huddlesEnabled()) {
            return false;
        }

        if (! app(HuddleTokens::class)->isConfigured()) {
            return false;
        }

        return $user->belongsToRoom($room);
    }

    public function create(User $user): bool
    {
        // Any employee may start a room. Keeping this open is what makes the
        // product lighter than the alternative.
        return true;
    }

    public function update(User $user, Room $room): bool
    {
        return ! $room->is_system && $this->moderate($user, $room);
    }

    public function delete(User $user, Room $room): bool
    {
        return ! $room->is_system && ! $room->isDm() && $user->can(Permissions::MANAGE_ROOMS);
    }
}
