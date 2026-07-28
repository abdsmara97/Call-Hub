<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorisation is the second gate, not the only one. Every Livewire
 * action re-checks the policy server-side — a subscriber must never be able to
 * act on a room just because the socket let them listen.
 */

/** Personal channel: emergencies, escalations, room invitations. */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->getKey() === $id;
});

/** Room traffic. Membership only — public rooms still require joining first. */
Broadcast::channel('room.{room}', function (User $user, Room $room) {
    return $user->belongsToRoom($room);
});

/**
 * Presence channel for a room: powers "who is here" and carries typing
 * indicators as client whispers, so typing never hits the server.
 */
Broadcast::channel('presence.room.{room}', function (User $user, Room $room) {
    if (! $user->belongsToRoom($room)) {
        return false;
    }

    return [
        'id' => $user->getKey(),
        'name' => $user->name,
        'availability' => $user->availability->value,
        'avatar_url' => $user->avatar_url,
    ];
});

/** Workspace-wide presence. Fine to keep global at a 1,000-account ceiling. */
Broadcast::channel('presence.online', function (User $user) {
    return $user->isActive()
        ? ['id' => $user->getKey(), 'name' => $user->name, 'availability' => $user->availability->value]
        : false;
});
