<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('room.'.$this->message->room_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Deliberately lean: the client uses this as a nudge to refresh, so the
     * payload never has to carry authorisation-sensitive content.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->getKey(),
            'room_id' => $this->message->room_id,
            'user_id' => $this->message->user_id,
            'parent_id' => $this->message->parent_id,
            'is_emergency' => $this->message->isEmergency(),
        ];
    }
}
