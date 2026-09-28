<?php

namespace App\Policies;

use App\Models\FormResponse;
use App\Models\User;

/**
 * One person's answers.
 *
 * Its own policy rather than a method on FormPolicy because the subject is a
 * FormResponse — policy discovery resolves by model class, and a rule filed
 * under the wrong model is a rule that never runs.
 */
class FormResponsePolicy
{
    /**
     * Your own answers are always yours to re-read, which is what lets the fill
     * screen show you what you sent after the form has closed. Everyone else
     * needs the right to read the form's responses as a whole.
     */
    public function view(User $user, FormResponse $response): bool
    {
        return $response->user_id === $user->getKey()
            || $user->can('viewResponses', $response->form);
    }
}
