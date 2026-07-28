<?php

namespace App\Policies;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\User;
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
