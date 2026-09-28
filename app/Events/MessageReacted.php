<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Support\BroadcastChannels;

/**
 * A reaction was added or removed, so everyone watching the room sees the count
 * move without refreshing.
 */
class MessageReacted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $messageId,
        public int $roomId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(BroadcastChannels::roomId($this->roomId))];
    }

    public function broadcastAs(): string
    {
        return 'message.reacted';
    }

    /** A nudge to re-read, like the other message events. */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->messageId,
            'room_id' => $this->roomId,
        ];
    }
}
