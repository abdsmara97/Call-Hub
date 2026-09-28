<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\Room;
use App\Models\User;
use App\Support\Permissions;

/**
 * Three separate rights, deliberately not collapsed into one.
 *
 * Writing a form is an admin act in the admin area. Sending one into a
 * conversation is a second act, and needs both the permission and membership of
 * that conversation. Reading the answers is narrower than either: a form is
 * visible wherever it was sent, but what people wrote is not.
 */
class FormPolicy
{
    /** Authoring a form. Nothing is sent anywhere by doing this. */
    public function create(User $user): bool
    {
        return $user->can(Permissions::MANAGE_FORMS);
    }

    public function update(User $user, Form $form): bool
    {
        // Once people have started answering, changing the questions would
        // silently change what the existing answers were answers to.
        return $this->create($user) && ! $form->isPosted();
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->create($user) && $form->responseCount() === 0;
    }

    /**
     * Seeing a form: anyone who can see a conversation it was sent into, plus
     * the people who administer it — who need to read it before sending it.
     */
    public function view(User $user, Form $form): bool
    {
        if ($this->viewResponses($user, $form)) {
            return true;
        }

        return $form->rooms()->get()->contains(fn (Room $room) => $user->can('view', $room));
    }

    /**
     * Sending a form into a conversation. The permission grants the authority;
     * membership is still required, so an admin cannot drop a form into a
     * private room or somebody else's direct message from the outside.
     */
    public function postIn(User $user, Room $room): bool
    {
        return $user->can(Permissions::MANAGE_FORMS) && $user->can('post', $room);
    }

    /**
     * Filling one in needs membership of a room it was sent into — not just
     * read access. Someone browsing a public room they have not joined can see
     * the form but not answer it, which is how polls behave too.
     */
    public function respond(User $user, Form $form): bool
    {
        return $form->isOpen()
            && $form->rooms()->get()->contains(fn (Room $room) => $user->belongsToRoom($room));
    }

    /** Reading everyone's answers. Deliberately narrow. */
    public function viewResponses(User $user, Form $form): bool
    {
        return $form->created_by === $user->getKey()
            || $user->can(Permissions::MANAGE_FORMS);
    }

    /** Closing is final, so it stays with the author and administrators. */
    public function close(User $user, Form $form): bool
    {
        return $form->isOpen() && $this->viewResponses($user, $form);
    }
}
