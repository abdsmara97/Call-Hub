<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\SavedMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The signed-in user's saved messages.
 *
 * Saving a message is a bookmark, not a copy: if the message is later deleted,
 * or the user loses access to the room it lives in, the bookmark stops being
 * readable. Both conditions are filtered in SQL so pagination counts stay honest,
 * and re-checked through the policy before anything is rendered.
 */
#[Layout('layouts.app')]
class SavedMessages extends Component
{
    use WithPagination;

    public string $status = '';

    private const PER_PAGE = 20;

    private function user(): User
    {
        return Auth::user();
    }

    public function unsave(int $savedMessageId): void
    {
        $deleted = SavedMessage::query()
            ->whereKey($savedMessageId)
            ->where('user_id', $this->user()->getKey())
            ->delete();

        $this->status = $deleted
            ? 'Message removed from your saved list.'
            : 'That saved message no longer exists.';
    }

    public function render()
    {
        $user = $this->user();

        $saved = SavedMessage::query()
            ->where('user_id', $user->getKey())
            // whereHas('message') applies the soft-delete scope, so a deleted
            // message drops out; readableBy() drops rooms the user can no
            // longer see.
            ->whereHas('message', fn (Builder $q) => $q->readableBy($user))
            ->with([
                'message.author',
                'message.room.members',
            ])
            ->latest()
            ->paginate(self::PER_PAGE);

        // Belt and braces: the policy is the authority on visibility, so every
        // row that survived the query is asked one more time.
        $saved->setCollection(
            $saved->getCollection()->filter(
                fn (SavedMessage $entry) => $entry->message instanceof Message
                    && Gate::forUser($user)->allows('view', $entry->message)
            )->values()
        );

        return view('livewire.saved-messages', [
            'saved' => $saved,
        ]);
    }
}
