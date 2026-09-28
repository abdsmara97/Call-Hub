<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use App\Support\BroadcastChannels;

/**
 * The state of a room's huddle changed.
 *
 * Goes to the room's existing private channel rather than its presence channel,
 * and that choice is the feature: the banner has to reach members who are not
 * currently looking at the room, and presence only knows who is. `room.{room}`
 * is already membership-gated in routes/channels.php, so huddles add no channel
 * and no new authorisation surface.
 *
 * Note this is deliberately not a "someone joined" event. Every payload is a
 * complete snapshot with a monotonic version, which is what makes a dropped or
 * out-of-order delivery harmless — the client keeps the highest version it has
 * seen and discards the rest.
 */
class HuddleUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public int $roomId,
        public array $snapshot,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(BroadcastChannels::roomId($this->roomId))];
    }

    public function broadcastAs(): string
    {
        return 'huddle.updated';
    }

    /**
     * The snapshot is already shaped for the wire by HuddleRegistry, including
     * the participant cap that keeps this payload under Reverb's message limit.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->snapshot;
    }
}
