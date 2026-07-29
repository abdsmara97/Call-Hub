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

    /**
     * Rooms whose badge is currently blinking, as room id => true.
     *
     * @var array<int, bool>
     */
    public array $blinking = [];

    /** Refreshed whenever anything could change unread counts or the room list. */
    protected function getListeners(): array
    {
        $listeners = [
            'echo-private:App.Models.User.'.auth()->id().',.room.membership' => '$refresh',
            'echo-private:App.Models.User.'.auth()->id().',.emergency.sent' => '$refresh',
            'room-list-changed' => '$refresh',
            'messages-read' => '$refresh',
        ];

        /*
         * Subscribe to every room the user belongs to, not just the one that is
         * open. Without this the badge for a room you are not looking at never
         * moves until something else happens to re-render the sidebar — which
         * is the whole case unread counts exist for.
         */
        foreach ($this->joinedRoomIds() as $roomId) {
            $listeners["echo-private:room.{$roomId},.message.sent"] = 'onRoomMessage';
        }

        return $listeners;
    }

    /** @return array<int, int> */
    private function joinedRoomIds(): array
    {
        return RoomMember::query()
            ->where('user_id', auth()->id())
            ->pluck('room_id')
            ->all();
    }

    /**
     * A message landed in one of the user's rooms.
     *
     * @param  array<string, mixed>  $payload
     */
    public function onRoomMessage(array $payload = []): void
    {
        $roomId = (int) ($payload['room_id'] ?? 0);

        // Your own message is not news, and neither is one in the room you are
        // already reading — that conversation marks itself read on arrival.
        if (
            $roomId
            && $roomId !== $this->activeRoomId
            && (int) ($payload['user_id'] ?? 0) !== auth()->id()
        ) {
            $this->blinking[$roomId] = true;
        }
    }

    /** Alpine calls this when the blink animation has finished playing. */
    public function stopBlinking(int $roomId): void
    {
        unset($this->blinking[$roomId]);
    }

    #[On('message-received')]
    public function refreshCounts(): void
    {
        // No-op body: the re-render itself recalculates the badges.
    }

    #[On('room-opened')]
    public function clearBlinkForOpenedRoom(int $roomId): void
    {
        unset($this->blinking[$roomId]);
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
