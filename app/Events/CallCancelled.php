<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The caller hung up before the callee answered.
 *
 * This exists because a whisper cannot un-ring someone who never joined the
 * presence channel — a callee whose tab is backgrounded, or who is on a page
 * that has not yet subscribed. The client also sends a hangup whisper, which
 * usually arrives first; whichever lands first wins and the second is dropped
 * by the client's call id guard.
 */
class CallCancelled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callId,
        public User $callee,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->callee->getKey())];
    }

    public function broadcastAs(): string
    {
        return 'call.cancelled';
    }

    public function broadcastWith(): array
    {
        return ['call_id' => $this->callId];
    }
}
