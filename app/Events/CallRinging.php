<?php

namespace App\Events;

use App\Models\Room;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone is calling. Goes to the callee's personal channel only.
 *
 * The personal channel is what makes a call reach a person who is reading a
 * different room, or the directory — exactly the reason EmergencySent uses it.
 * It deliberately does NOT broadcast on room.{id}: a direct message has two
 * members, and the other one is the caller, who already knows.
 *
 * Everything after this — accept, decline, SDP, ICE, hangup — is a client
 * whisper on the room's presence channel and never touches the server. This
 * event exists only because a whisper cannot reach someone who has not joined
 * the channel yet.
 */
class CallRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callId,
        public Room $room,
        public User $caller,
        public User $callee,
        public bool $quiet,
        public bool $video,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->callee->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'call.ringing';
    }

    /**
     * Only what the overlay needs to render. No email, no phone — a ring is not
     * a reason to hand out contact details the directory already gates.
     */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'room_id' => $this->room->getKey(),
            'caller_id' => $this->caller->getKey(),
            'caller_name' => $this->caller->name,
            'caller_avatar_url' => $this->caller->avatar_url,
            'quiet' => $this->quiet,
            'video' => $this->video,
        ];
    }
}
