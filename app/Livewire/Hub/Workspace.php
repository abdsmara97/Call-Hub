<?php

namespace App\Livewire\Hub;

use App\Enums\RoomType;
use App\Models\Room;
use App\Services\RoomProvisioner;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Page shell for the messaging area. Owns nothing but "which room is open" —
 * the sidebar and the conversation are separate components so a new message
 * does not re-render the whole navigation.
 */
#[Layout('layouts.app')]
class Workspace extends Component
{
    public ?int $roomId = null;

    public function mount(?Room $room = null, RoomProvisioner $rooms): void
    {
        if (! $room?->exists) {
            $this->roomId = $this->defaultRoomId();

            return;
        }

        Gate::authorize('view', $room);

        // Opening a public room you have not joined joins you to it. Anything
        // else would leave you reading a room you cannot post in.
        if ($room->type === RoomType::Public && ! auth()->user()->belongsToRoom($room)) {
            $rooms->addMember($room, auth()->user());
        }

        $this->roomId = $room->getKey();
    }

    #[On('room-opened')]
    public function openRoom(int $roomId): void
    {
        $room = Room::findOrFail($roomId);

        Gate::authorize('view', $room);

        $this->roomId = $roomId;
    }

    /** The room the user most recently had activity in, else their first. */
    private function defaultRoomId(): ?int
    {
        return Room::query()
            ->whereHas('memberships', fn ($q) => $q->where('user_id', auth()->id()))
            ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_message_at')
            ->value('id');
    }

    public function render()
    {
        return view('livewire.hub.workspace');
    }
}
