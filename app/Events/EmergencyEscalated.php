<?php

namespace App\Events;

use App\Models\Emergency;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Re-alert for people who have not acknowledged, plus a notice to the sender so
 * they know their message has not landed.
 */
class EmergencyEscalated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  list<int>  $pendingUserIds */
    public function __construct(
        public Emergency $emergency,
        public array $pendingUserIds,
    ) {}

    public function broadcastOn(): array
    {
        $channels = array_map(
            fn (int $id) => new PrivateChannel('App.Models.User.'.$id),
            $this->pendingUserIds,
        );

        $channels[] = new PrivateChannel('App.Models.User.'.$this->emergency->sender_id);

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'emergency.escalated';
    }

    public function broadcastWith(): array
    {
        $this->emergency->loadMissing('sender');

        return [
            'id' => $this->emergency->getKey(),
            'body' => $this->emergency->body,
            'sender_id' => $this->emergency->sender_id,
            'sender_name' => $this->emergency->sender->name,
            'room_id' => $this->emergency->room_id,
            'escalation_count' => $this->emergency->escalation_count,
            'pending_count' => count($this->pendingUserIds),
        ];
    }
}
