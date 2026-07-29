<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Message extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $fillable = [
        'room_id',
        'user_id',
        'parent_id',
        'emergency_id',
        'body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------- relationships

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function emergency(): BelongsTo
    {
        return $this->belongsTo(Emergency::class);
    }

    /**
     * A poll is posted as an ordinary message carrying the question, so it
     * threads, pins, saves and searches like anything else in the room.
     */
    public function poll(): HasOne
    {
        return $this->hasOne(Poll::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Reactions grouped for display: one entry per emoji, in the order each was
     * first used, with who reacted and whether the viewer is among them.
     *
     * Reads the loaded relation when the caller eager-loaded it, so rendering a
     * page of messages does not cost a query per row.
     *
     * @return list<array{emoji: string, count: int, mine: bool, who: string}>
     */
    public function reactionSummary(User $viewer): array
    {
        $reactions = $this->relationLoaded('reactions')
            ? $this->reactions
            : $this->reactions()->with('user')->get();

        return $reactions
            ->sortBy('id')
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => (string) $emoji,
                'count' => $group->count(),
                'mine' => $group->contains('user_id', $viewer->getKey()),
                'who' => $group
                    ->map(fn (MessageReaction $r) => $r->user_id === $viewer->getKey()
                        ? 'You'
                        : ($r->user?->name ?? 'Someone'))
                    ->join(', ', ' and '),
            ])
            ->values()
            ->all();
    }

    // ----------------------------------------------------------------- scopes

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Restricts a query to rooms the user can actually read. */
    public function scopeReadableBy(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'room',
            fn (Builder $room) => $room->visibleTo($user)
        );
    }

    // ------------------------------------------------------------- behaviours

    public function isEmergency(): bool
    {
        return $this->emergency_id !== null;
    }

    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isThreadReply(): bool
    {
        return $this->parent_id !== null;
    }

    // ------------------------------------------------------------------ scout

    /**
     * Only the body is indexed. Access control is applied at query time by
     * intersecting hits with the rooms the searcher belongs to — never trust
     * the index itself to enforce authorisation.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'body' => (string) $this->body,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return filled($this->body);
    }
}
