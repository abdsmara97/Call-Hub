<?php

namespace App\Support;

use App\Models\Room;

/**
 * The single place broadcast channel names are spelled.
 *
 * Room traffic rides `tenant.{tenant}.room.{room}` so one shared Reverb
 * cluster can serve every customer: the name itself carries the boundary,
 * and the authorisation callback refuses a socket whose user is in a
 * different tenant before membership is even considered. Personal channels
 * stay `App.Models.User.{id}` — user ids are globally unique and the
 * callback only ever admits the user themselves.
 */
class BroadcastChannels
{
    public static function room(Room $room): string
    {
        return 'tenant.'.$room->tenant_id.'.room.'.$room->getKey();
    }

    /**
     * For events that only carry a room id. One indexed lookup, and it runs
     * on the queue worker at broadcast time, not on the request path.
     */
    public static function roomId(int $roomId): string
    {
        $tenantId = Room::acrossTenants()->whereKey($roomId)->value('tenant_id');

        return 'tenant.'.$tenantId.'.room.'.$roomId;
    }

    public static function presenceRoom(Room $room): string
    {
        return 'tenant.'.$room->tenant_id.'.presence.room.'.$room->getKey();
    }

    public static function presenceOnline(int $tenantId): string
    {
        return 'tenant.'.$tenantId.'.presence.online';
    }
}
