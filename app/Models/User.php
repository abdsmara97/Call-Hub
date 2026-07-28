<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\RoomMemberRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasPushSubscriptions, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'company_id',
        'administration_id',
        'job_title',
        'status',
        'must_change_password',
        'avatar_path',
        'status_message',
        'availability',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'status' => UserStatus::class,
            'availability' => Availability::class,
        ];
    }

    // ---------------------------------------------------------- relationships

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(Administration::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'room_members')
            ->using(RoomMember::class)
            ->withPivot(['role', 'last_read_message_id', 'is_muted', 'joined_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function sentEmergencies(): HasMany
    {
        return $this->hasMany(Emergency::class, 'sender_id');
    }

    /** The acknowledgement duties assigned to this user. */
    public function emergencyRecipients(): HasMany
    {
        return $this->hasMany(EmergencyRecipient::class);
    }

    public function savedMessages(): HasMany
    {
        return $this->hasMany(SavedMessage::class);
    }

    public function dndWindows(): HasMany
    {
        return $this->hasMany(DndWindow::class);
    }

    // ----------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('job_title', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }

    // ------------------------------------------------------------- behaviours

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function moderatesRoom(Room $room): bool
    {
        $membership = $this->membershipFor($room);

        return $membership?->role === RoomMemberRole::Moderator;
    }

    public function membershipFor(Room $room): ?RoomMember
    {
        return RoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $this->getKey())
            ->first();
    }

    public function belongsToRoom(Room $room): bool
    {
        return RoomMember::query()
            ->where('room_id', $room->getKey())
            ->where('user_id', $this->getKey())
            ->exists();
    }

    /**
     * Do Not Disturb suppresses ordinary notifications only. Emergency delivery
     * deliberately ignores this — see EmergencyNotifier.
     */
    public function isWithinDndWindow(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Carbon\CarbonImmutable::instance(\Carbon\Carbon::instance($at)) : now()->toImmutable();
        $time = $at->format('H:i:s');

        return $this->dndWindows()
            ->where('day_of_week', (int) $at->format('w'))
            ->where(function ($query) use ($time) {
                $query
                    // Same-day window, e.g. 09:00 → 17:00.
                    ->where(function ($q) use ($time) {
                        $q->whereColumn('starts_at', '<=', 'ends_at')
                            ->where('starts_at', '<=', $time)
                            ->where('ends_at', '>=', $time);
                    })
                    // Overnight window, e.g. 22:00 → 06:00.
                    ->orWhere(function ($q) use ($time) {
                        $q->whereColumn('starts_at', '>', 'ends_at')
                            ->where(function ($inner) use ($time) {
                                $inner->where('starts_at', '<=', $time)
                                    ->orWhere('ends_at', '>=', $time);
                            });
                    });
            })
            ->exists();
    }

    // ------------------------------------------------------------- accessors

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? Storage::disk(config('hub.avatar_disk'))->url($this->avatar_path)
            : null;
    }

    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last) ?: '?';
    }
}
