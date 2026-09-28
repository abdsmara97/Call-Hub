<?php

namespace App\Events;

use App\Models\Form;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a form is sent somewhere, answered or closed, so a room watching
 * one sees the response count move without refreshing.
 *
 * A form has no single room, so this goes to every room it has been sent into
 * — except when a room is named, which is the case where a form has just
 * landed somewhere and the other rooms have nothing new to hear about.
 */
class FormUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Form $form, public ?int $roomId = null) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $rooms = $this->roomId !== null
            ? [$this->roomId]
            : $this->form->postings()->pluck('room_id')->all();

        return array_map(fn ($id) => new PrivateChannel('room.'.$id), $rooms);
    }

    public function broadcastAs(): string
    {
        return 'form.updated';
    }

    /**
     * A nudge to refresh, like the poll event. Nobody's answers travel over the
     * channel — a room can see one more person responded, never what they said.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->form->getKey(),
            'closed' => $this->form->isClosed(),
        ];
    }
}
