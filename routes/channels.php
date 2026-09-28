<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorisation is the second gate, not the only one. Every Livewire
 * action re-checks the policy server-side — a subscriber must never be able to
 * act on a room just because the socket let them listen.
 *
 * Room and presence channels carry the tenant id in the name so one shared
 * Reverb cluster serves every customer: the callback refuses a socket whose
 * user is in a different tenant before membership is even considered. The
 * {room} binding resolves under the caller's tenant scope, so a cross-tenant
 * room id 404s here rather than reaching the membership check.
 */

/** Personal channel: emergencies, escalations, room invitations. */
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->getKey() === $id;
});

/** Room traffic. Membership only — public rooms still require joining first. */
Broadcast::channel('tenant.{tenantId}.room.{room}', function (User $user, int $tenantId, Room $room) {
    return $user->tenant_id === $tenantId
        && $room->tenant_id === $tenantId
        && $user->belongsToRoom($room);
});

/**
 * Presence channel for a room: powers "who is here" and carries typing
 * indicators as client whispers, so typing never hits the server.
 */
Broadcast::channel('tenant.{tenantId}.presence.room.{room}', function (User $user, int $tenantId, Room $room) {
    if ($user->tenant_id !== $tenantId || $room->tenant_id !== $tenantId || ! $user->belongsToRoom($room)) {
        return false;
    }

    return [
        'id' => $user->getKey(),
        'name' => $user->name,
        'availability' => $user->availability->value,
        'avatar_url' => $user->avatar_url,
    ];
});

/** Workspace-wide presence — one channel per tenant, never shared across customers. */
Broadcast::channel('tenant.{tenantId}.presence.online', function (User $user, int $tenantId) {
    return $user->tenant_id === $tenantId && $user->isActive()
        ? ['id' => $user->getKey(), 'name' => $user->name, 'availability' => $user->availability->value]
        : false;
});
