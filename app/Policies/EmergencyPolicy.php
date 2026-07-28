<?php

namespace App\Policies;

use App\Models\Emergency;
use App\Models\Room;
use App\Models\User;
use App\Support\Permissions;

class EmergencyPolicy
{
    /** Any employee may raise an emergency in a room they belong to. */
    public function sendInRoom(User $user, Room $room): bool
    {
        return $user->belongsToRoom($room);
    }

    /** Company / administration / everyone broadcasts are administrator-only. */
    public function broadcast(User $user): bool
    {
        return $user->can(Permissions::BROADCAST_EMERGENCY);
    }

    public function view(User $user, Emergency $emergency): bool
    {
        return $user->is($emergency->sender)
            || $user->can(Permissions::VIEW_EMERGENCY_LOG)
            || $emergency->recipients()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Only the named recipient may acknowledge, and only for themselves — an
     * acknowledgement is a statement about one person having seen it.
     */
    public function acknowledge(User $user, Emergency $emergency): bool
    {
        return $emergency->recipients()
            ->where('user_id', $user->getKey())
            ->whereNull('acknowledged_at')
            ->exists();
    }

    public function resolve(User $user, Emergency $emergency): bool
    {
        return $user->is($emergency->sender) || $user->can(Permissions::VIEW_EMERGENCY_LOG);
    }

    public function viewLog(User $user): bool
    {
        return $user->can(Permissions::VIEW_EMERGENCY_LOG);
    }

    public function export(User $user): bool
    {
        return $user->can(Permissions::VIEW_EMERGENCY_LOG);
    }
}
