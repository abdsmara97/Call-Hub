<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        return $user->can('view', $message->room);
    }

    /**
     * Authors may edit their own words inside a time window. Emergencies are
     * excluded — the audit trail must reflect what was actually broadcast.
     */
    public function update(User $user, Message $message): bool
    {
        if ($message->isEmergency() || ! $user->is($message->author)) {
            return false;
        }

        $window = (int) config('hub.messages.edit_window_minutes');

        return $message->created_at->diffInMinutes(now()) < $window;
    }

    public function delete(User $user, Message $message): bool
    {
        if ($message->isEmergency()) {
            return false;
        }

        return $user->is($message->author) || $user->can('moderate', $message->room);
    }

    public function pin(User $user, Message $message): bool
    {
        return $user->can('moderate', $message->room);
    }

    public function save(User $user, Message $message): bool
    {
        return $this->view($user, $message);
    }

    /**
     * Reacting needs membership, not just read access — someone browsing a
     * public room they have not joined can see the tally but not move it. Same
     * rule as posting and as voting in a poll.
     */
    public function react(User $user, Message $message): bool
    {
        return $message->deleted_at === null && $user->belongsToRoom($message->room);
    }
}
