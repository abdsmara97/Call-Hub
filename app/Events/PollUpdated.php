<?php

namespace App\Events;

use App\Models\Poll;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires on every vote, retraction and close, so a room watching a poll sees the
 * bars move without refreshing.
 */
class PollUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Poll $poll) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('room.'.$this->poll->room_id)];
    }

    public function broadcastAs(): string
    {
        return 'poll.updated';
    }

    /**
     * A nudge to refresh, like the message events. The tally is re-read
     * server-side so nobody's vote arrives over a channel as a payload.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->poll->getKey(),
            'room_id' => $this->poll->room_id,
            'closed' => $this->poll->isClosed(),
        ];
    }
}
