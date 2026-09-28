<?php

namespace App\Events;

use App\Models\Emergency;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Support\BroadcastChannels;

/**
 * The headline event. Fires on the originating room AND on every recipient's
 * personal channel — the personal copy is what makes the alert reach someone
 * sitting in a different room, or with the tab in the background.
 */
class EmergencySent implements ShouldBroadcastNow
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
            $channels[] = new PrivateChannel(BroadcastChannels::roomId($this->emergency->room_id));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'emergency.sent';
    }

    public function broadcastWith(): array
    {
        $this->emergency->loadMissing('sender');

        return [
            'id' => $this->emergency->getKey(),
            'body' => $this->emergency->body,
            'scope' => $this->emergency->scope->value,
            'scope_label' => $this->emergency->scope->label(),
            'is_broadcast' => $this->emergency->isBroadcast(),
            'room_id' => $this->emergency->room_id,
            'sender_id' => $this->emergency->sender_id,
            'sender_name' => $this->emergency->sender->name,
            'target' => $this->emergency->targetLabel(),
            'sent_at' => $this->emergency->created_at?->toIso8601String(),
        ];
    }
}
