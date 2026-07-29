<?php

namespace App\Livewire;

use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Everywhere the signed-in user has been named.
 *
 * A mention is a record of what a message said, not a permission: losing access
 * to the room hides it again. Both that and the soft-delete are filtered in SQL
 * so pagination counts stay honest, then re-checked through the policy before
 * anything renders — the same belt-and-braces shape as SavedMessages.
 */
#[Layout('layouts.app')]
class Mentions extends Component
{
    use WithPagination;

    public string $status = '';

    private const PER_PAGE = 20;

    private function user(): User
    {
        return Auth::user();
    }

    public function markAllRead(): void
    {
        $marked = MessageMention::query()
            ->where('user_id', $this->user()->getKey())
            ->unread()
            ->update(['read_at' => now()]);

        $this->status = $marked > 0
            ? 'All mentions marked as read.'
            : 'You had no unread mentions.';
    }

    public function render()
    {
        $user = $this->user();

        $mentions = MessageMention::query()
            ->where('user_id', $user->getKey())
            // whereHas('message') applies the soft-delete scope, so a deleted
            // message drops out; readableBy() drops rooms the user can no
            // longer see. Naming yourself is not a mention worth listing.
            ->whereHas('message', fn (Builder $q) => $q->readableBy($user)->where('user_id', '!=', $user->getKey()))
            ->with([
                'message.author',
                'message.room.members',
                // The rendered body resolves its own spans.
                'message.mentions',
            ])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $mentions->setCollection(
            $mentions->getCollection()->filter(
                fn (MessageMention $entry) => $entry->message instanceof Message
                    && Gate::forUser($user)->allows('view', $entry->message)
            )->values()
        );

        return view('livewire.mentions', [
            'mentions' => $mentions,
            'unreadCount' => MessageMention::query()
                ->where('user_id', $user->getKey())
                ->unread()
                ->count(),
        ]);
    }
}
