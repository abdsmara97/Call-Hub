<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Poll extends Model
{
    protected $fillable = ['room_id', 'message_id', 'created_by', 'question', 'closes_at'];

    protected function casts(): array
    {
        return ['closes_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** The timeline message this poll was posted as. */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->isPast();
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function voteOf(User $user): ?PollVote
    {
        return $this->votes()->where('user_id', $user->getKey())->first();
    }

    /**
     * Which option the user picked. Reads the already-loaded votes relation
     * when the caller eager-loaded it, so rendering a room full of polls does
     * not cost a query each.
     */
    public function chosenOptionIdFor(User $user): ?int
    {
        $vote = $this->relationLoaded('votes')
            ? $this->votes->firstWhere('user_id', $user->getKey())
            : $this->voteOf($user);

        return $vote?->poll_option_id;
    }

    public function totalVotes(): int
    {
        return $this->relationLoaded('votes')
            ? $this->votes->count()
            : $this->votes()->count();
    }

    /**
     * Votes per option id, including options nobody picked. Results stay
     * visible while the poll is open — this is a coordination tool, not a
     * secret ballot.
     *
     * @return array<int, int>
     */
    public function tally(): array
    {
        $counts = $this->relationLoaded('votes')
            ? $this->votes->countBy('poll_option_id')->all()
            : $this->votes()
                ->selectRaw('poll_option_id, count(*) as aggregate')
                ->groupBy('poll_option_id')
                ->pluck('aggregate', 'poll_option_id')
                ->all();

        $tally = [];

        foreach ($this->options as $option) {
            $tally[$option->getKey()] = (int) ($counts[$option->getKey()] ?? 0);
        }

        return $tally;
    }

    /** Whole-percent share for one option; 0 when nobody has voted yet. */
    public function shareOf(PollOption $option): int
    {
        $total = $this->totalVotes();

        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->tally()[$option->getKey()] ?? 0) / $total * 100);
    }
}
