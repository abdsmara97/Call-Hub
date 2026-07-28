<?php

namespace App\Livewire\Hub;

use App\Enums\RoomType;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

class Sidebar extends Component
{
    public ?int $activeRoomId = null;

    public string $filter = '';

    public bool $showCreate = false;

    public bool $showBrowse = false;

    public string $newRoomName = '';

    public string $newRoomTopic = '';

    public string $newRoomType = 'public';

    /** Refreshed whenever anything could change unread counts or the room list. */
    protected function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.auth()->id().',.room.membership' => '$refresh',
            'echo-private:App.Models.User.'.auth()->id().',.emergency.sent' => '$refresh',
            'room-list-changed' => '$refresh',
            'messages-read' => '$refresh',
        ];
    }

    #[On('message-received')]
    public function refreshCounts(): void
    {
        // No-op body: the re-render itself recalculates the badges.
    }

    public function createRoom(RoomProvisioner $rooms): void
    {
        Gate::authorize('create', Room::class);

        $validated = $this->validate([
            'newRoomName' => ['required', 'string', 'min:2', 'max:60'],
            'newRoomTopic' => ['nullable', 'string', 'max:160'],
            'newRoomType' => ['required', 'in:public,private'],
        ], attributes: [
            'newRoomName' => 'room name',
            'newRoomTopic' => 'topic',
            'newRoomType' => 'visibility',
        ]);

        $room = $rooms->createRoom(
            auth()->user(),
            $validated['newRoomName'],
            RoomType::from($validated['newRoomType']),
            $validated['newRoomTopic'] ?: null,
        );

        $this->reset('newRoomName', 'newRoomTopic', 'newRoomType', 'showCreate');
        $this->activeRoomId = $room->getKey();

        $this->dispatch('room-opened', roomId: $room->getKey());
    }

    public function joinRoom(int $roomId, RoomProvisioner $rooms): void
    {
        $room = Room::findOrFail($roomId);

        Gate::authorize('join', $room);

        $rooms->addMember($room, auth()->user());

        $this->showBrowse = false;
        $this->dispatch('room-opened', roomId: $room->getKey());
    }

    public function render()
    {
        $user = auth()->user();

        $memberships = RoomMember::query()
            ->where('user_id', $user->getKey())
            ->with(['room.members' => fn ($q) => $q->select('users.id', 'name', 'avatar_path', 'availability')])
            ->get();

        $rooms = $memberships->map(fn (RoomMember $m) => $m->room)->filter();

        // Unread counts in one grouped query rather than one per room.
        $unread = $this->unreadCounts($memberships);

        $matches = fn (Room $room) => $this->filter === ''
            || str_contains(
                mb_strtolower($room->displayNameFor($user)),
                mb_strtolower($this->filter)
            );

        return view('livewire.hub.sidebar', [
            'systemRooms' => $rooms->where('is_system', true)->filter($matches)
                ->sortBy(fn (Room $r) => $r->displayNameFor($user))->values(),
            'channels' => $rooms->where('is_system', false)
                ->reject(fn (Room $r) => $r->isDm())->filter($matches)
                ->sortBy(fn (Room $r) => $r->displayNameFor($user))->values(),
            'conversations' => $rooms->filter(fn (Room $r) => $r->isDm())->filter($matches)
                ->sortByDesc('last_message_at')->values(),
            'unread' => $unread,
            'browsable' => $this->showBrowse ? $this->browsableRooms($rooms->pluck('id')) : collect(),
        ]);
    }

    /**
     * @param  Collection<int, RoomMember>  $memberships
     * @return array<int, int>
     */
    private function unreadCounts(Collection $memberships): array
    {
        if ($memberships->isEmpty()) {
            return [];
        }

        $counts = [];

        foreach ($memberships as $membership) {
            $counts[$membership->room_id] = (int) \App\Models\Message::query()
                ->where('room_id', $membership->room_id)
                ->where('user_id', '!=', auth()->id())
                ->when(
                    $membership->last_read_message_id,
                    fn ($q) => $q->where('id', '>', $membership->last_read_message_id)
                )
                ->count();
        }

        return $counts;
    }

    /** @param  Collection<int, int>  $joinedIds */
    private function browsableRooms(Collection $joinedIds): Collection
    {
        return Room::query()
            ->where('type', RoomType::Public->value)
            ->whereNotIn('id', $joinedIds)
            ->withCount('memberships')
            ->orderBy('name')
            ->limit(50)
            ->get();
    }
}
