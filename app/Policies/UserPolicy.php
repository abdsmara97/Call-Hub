<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        // The directory is open to every employee by design.
        return true;
    }

    public function view(User $actor, User $target): bool
    {
        return true;
    }

    public function manage(User $actor): bool
    {
        return $actor->can(Permissions::MANAGE_USERS);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permissions::MANAGE_USERS);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can(Permissions::MANAGE_USERS);
    }

    /** Employees may edit only their own photo, phone and status message. */
    public function updateOwnProfile(User $actor, User $target): bool
    {
        return $actor->is($target);
    }

    public function suspend(User $actor, User $target): bool
    {
        // Locking yourself out is never the intent, so it is simply not allowed.
        return $actor->can(Permissions::MANAGE_USERS) && ! $actor->is($target);
    }

    public function import(User $actor): bool
    {
        return $actor->can(Permissions::IMPORT_USERS);
    }
}
