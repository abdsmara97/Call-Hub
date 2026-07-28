<?php

namespace App\Models;

use App\Enums\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'topic',
        'type',
        'is_system',
        'company_id',
        'administration_id',
        'created_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'is_system' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------- relationships

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'room_members')
            ->using(RoomMember::class)
            ->withPivot(['role', 'last_read_message_id', 'is_muted', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(Administration::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pinnedMessages(): HasMany
    {
        return $this->hasMany(PinnedMessage::class)->latest();
    }

    public function polls(): HasMany
    {
        return $this->hasMany(Poll::class);
    }

    /** Emergencies still awaiting acknowledgement stay pinned to the top. */
    public function activeEmergencies(): HasMany
    {
        return $this->hasMany(Emergency::class)->whereNull('resolved_at');
    }

    // ----------------------------------------------------------------- scopes

    public function scopeConversations(Builder $query): Builder
    {
        return $query->where('type', RoomType::Dm->value);
    }

    public function scopeChannels(Builder $query): Builder
    {
        return $query->whereIn('type', [RoomType::Public->value, RoomType::Private->value]);
    }

    /** Public rooms plus any private/DM room the user is actually in. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('type', RoomType::Public->value)
                ->orWhereHas('memberships', fn (Builder $m) => $m->where('user_id', $user->getKey()));
        });
    }

    // ------------------------------------------------------------- behaviours

    public function isDm(): bool
    {
        return $this->type === RoomType::Dm;
    }

    public function isPublic(): bool
    {
        return $this->type === RoomType::Public;
    }

    /**
     * DMs have no stored name — they are titled by whoever you are talking to.
     */
    public function displayNameFor(?User $viewer = null): string
    {
        if (! $this->isDm()) {
            return (string) $this->name;
        }

        $other = $this->members->first(fn (User $m) => $m->getKey() !== $viewer?->getKey());

        return $other?->name ?? 'Direct message';
    }

    public function otherMember(User $viewer): ?User
    {
        if (! $this->isDm()) {
            return null;
        }

        return $this->members->first(fn (User $m) => $m->getKey() !== $viewer->getKey());
    }

    public function unreadCountFor(User $user): int
    {
        $membership = $this->memberships->firstWhere('user_id', $user->getKey())
            ?? RoomMember::query()
                ->where('room_id', $this->getKey())
                ->where('user_id', $user->getKey())
                ->first();

        if (! $membership) {
            return 0;
        }

        return $this->messages()
            ->when(
                $membership->last_read_message_id,
                fn (Builder $q) => $q->where('id', '>', $membership->last_read_message_id)
            )
            ->where('user_id', '!=', $user->getKey())
            ->count();
    }

    public function broadcastChannelName(): string
    {
        return 'room.'.$this->getKey();
    }
}
