<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomMembershipChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  list<int>  $affectedUserIds */
    public function __construct(
        public int $roomId,
        public array $affectedUserIds = [],
    ) {}

    /** Fires on the room and on each affected person's own channel. */
    public function broadcastOn(): array
    {
        return array_merge(
            [new PrivateChannel('room.'.$this->roomId)],
            array_map(
                fn (int $id) => new PrivateChannel('App.Models.User.'.$id),
                $this->affectedUserIds,
            ),
        );
    }

    public function broadcastAs(): string
    {
        return 'room.membership';
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId];
    }
}
