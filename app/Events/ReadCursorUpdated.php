<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Support\BroadcastChannels;

/**
 * Read receipts move a cursor rather than marking individual messages, so one
 * event covers however many messages the reader just caught up on.
 */
class ReadCursorUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $roomId,
        public int $userId,
        public ?int $lastReadMessageId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(BroadcastChannels::roomId($this->roomId))];
    }

    public function broadcastAs(): string
    {
        return 'read.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
