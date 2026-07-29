<?php

namespace App\Policies;

use App\Models\Poll;
use App\Models\Room;
use App\Models\User;

/**
 * A poll inherits the room's access rules — it is never more visible, nor more
 * restricted, than the conversation it was posted in.
 */
class PollPolicy
{
    public function view(User $user, Poll $poll): bool
    {
        return $user->can('view', $poll->room);
    }

    /** Anyone who may post in the room may ask the room a question. */
    public function createIn(User $user, Room $room): bool
    {
        return $user->can('post', $room);
    }

    /**
     * Voting needs membership, not just read access: someone browsing a public
     * room they have not joined can see the result but not move it.
     */
    public function vote(User $user, Poll $poll): bool
    {
        return $poll->isOpen() && $user->belongsToRoom($poll->room);
    }

    /** Retracting a vote is the same right as casting one. */
    public function retractVote(User $user, Poll $poll): bool
    {
        return $this->vote($user, $poll);
    }

    /** Closing is final, so it stays with the author and the room moderators. */
    public function close(User $user, Poll $poll): bool
    {
        return $poll->isOpen()
            && ($poll->created_by === $user->getKey() || $user->can('moderate', $poll->room));
    }
}
