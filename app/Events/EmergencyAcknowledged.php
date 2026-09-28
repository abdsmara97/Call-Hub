<?php

namespace App\Events;

use App\Models\Emergency;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Support\BroadcastChannels;

/** Drives the live "who has and has not acknowledged" list. */
class EmergencyAcknowledged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Emergency $emergency,
        public User $recipient,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            // The sender is watching the acknowledgement list.
            new PrivateChannel('App.Models.User.'.$this->emergency->sender_id),
        ];

        if ($this->emergency->room_id) {
            $channels[] = new PrivateChannel(BroadcastChannels::roomId($this->emergency->room_id));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'emergency.acknowledged';
    }

    public function broadcastWith(): array
    {
        return [
            'emergency_id' => $this->emergency->getKey(),
            'user_id' => $this->recipient->getKey(),
            'user_name' => $this->recipient->name,
            'acknowledged_at' => now()->toIso8601String(),
            'pending' => $this->emergency->pendingCount(),
            'total' => $this->emergency->recipientCount(),
        ];
    }
}
