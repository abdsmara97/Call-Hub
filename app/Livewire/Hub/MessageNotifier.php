<?php

namespace App\Livewire\Hub;

use App\Models\Message;
use App\Models\RoomMember;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Desktop pop-ups for ordinary messages.
 *
 * Mounted on every page, like EmergencyAlerts — a message that arrives while the
 * user is on the directory or their profile still deserves a notification, and
 * the sidebar only exists on the hub.
 *
 * The browser decides whether to actually show anything: it only fires when the
 * tab is hidden. That check has to happen client-side, because the server has no
 * idea whether the window is minimised.
 */
class MessageNotifier extends Component
{
    protected function getListeners(): array
    {
        $listeners = [];

        // Same subscription set as the sidebar: every room the user belongs to,
        // not just the one on screen.
        foreach ($this->joinedRoomIds() as $roomId) {
            $listeners["echo-private:room.{$roomId},.message.sent"] = 'onMessage';
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

    /** @param  array<string, mixed>  $payload */
    public function onMessage(array $payload = []): void
    {
        $user = auth()->user();

        if (! $user || ! $user->notify_on_message) {
            return;
        }

        // Your own message is not news.
        if ((int) ($payload['user_id'] ?? 0) === $user->getKey()) {
            return;
        }

        // EmergencyAlerts owns emergencies, with its own overlay and sound. Two
        // pop-ups for one event is worse than none.
        if ($payload['is_emergency'] ?? false) {
            return;
        }

        // Quiet hours. Emergencies deliberately bypass this; ordinary messages
        // are exactly what it exists to suppress.
        if ($user->isWithinDndWindow()) {
            return;
        }

        $message = Message::with(['author', 'room'])->find($payload['id'] ?? null);

        // Re-authorise rather than trusting the subscription: a socket can
        // outlive the membership that justified it.
        if (! $message || ! $message->room || ! Gate::allows('view', $message->room)) {
            return;
        }

        $this->dispatch('message-notification',
            sender: $message->author?->name ?? 'A colleague',
            room: $message->room->displayNameFor($user),
            isDm: $message->room->isDm(),
            body: $this->preview($message),
            roomId: $message->room_id,
            url: route('rooms.show', $message->room_id),
        );
    }

    /** Attachment-only messages have no body worth previewing. */
    private function preview(Message $message): string
    {
        if (filled($message->body)) {
            return str($message->body)->limit(140)->toString();
        }

        return $message->attachments()->exists() ? 'Sent an attachment' : '';
    }

    public function render()
    {
        return view('livewire.hub.message-notifier');
    }
}
