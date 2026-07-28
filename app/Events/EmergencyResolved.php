<?php

namespace App\Events;

use App\Models\Emergency;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmergencyResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  list<int>  $recipientIds */
    public function __construct(
        public Emergency $emergency,
        public array $recipientIds,
    ) {}

    public function broadcastOn(): array
    {
        $channels = array_map(
            fn (int $id) => new PrivateChannel('App.Models.User.'.$id),
            $this->recipientIds,
        );

        if ($this->emergency->room_id) {
            $channels[] = new PrivateChannel('room.'.$this->emergency->room_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'emergency.resolved';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->emergency->getKey()];
    }
}
