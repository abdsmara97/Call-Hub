<?php

namespace App\Models;

use App\Enums\Availability;
use App\Enums\MessageNotificationLevel;
use App\Enums\RoomMemberRole;
use App\Enums\UserStatus;
use App\Models\Concerns\BelongsToTenant;
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
    use BelongsToTenant, HasFactory, HasPushSubscriptions, HasRoles, Notifiable;

    protected $fillable = [
        'tenant_id',
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
        'message_notifications',
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
            'is_super_admin' => 'boolean',
            'message_notifications' => MessageNotificationLevel::class,
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
     * deliberately ignores this — see App\Jobs\DispatchEmergencyNotifications.
     *
     * An overnight window belongs to the day it *starts* on, so 02:00 on Sunday
     * is covered by the Saturday 22:00 → 06:00 row, not by a Sunday row. Missing
     * that is how a quiet period leaks notifications through its second half.
     */
    public function isWithinDndWindow(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Carbon\CarbonImmutable::instance(\Carbon\Carbon::instance($at)) : now()->toImmutable();

        $time = $at->format('H:i:s');
        $today = (int) $at->format('w');
        $yesterday = ($today + 6) % 7;

        return $this->dndWindows()
            ->where(function ($query) use ($time, $today, $yesterday) {
                $query
                    // Same-day window today, e.g. 09:00 → 17:00.
                    ->orWhere(function ($q) use ($time, $today) {
                        $q->where('day_of_week', $today)
                            ->whereColumn('starts_at', '<=', 'ends_at')
                            ->where('starts_at', '<=', $time)
                            ->where('ends_at', '>=', $time);
                    })
                    // Overnight window that started today and runs past midnight.
                    ->orWhere(function ($q) use ($time, $today) {
                        $q->where('day_of_week', $today)
                            ->whereColumn('starts_at', '>', 'ends_at')
                            ->where('starts_at', '<=', $time);
                    })
                    // Overnight window that started yesterday and has not ended.
                    ->orWhere(function ($q) use ($time, $yesterday) {
                        $q->where('day_of_week', $yesterday)
                            ->whereColumn('starts_at', '>', 'ends_at')
                            ->where('ends_at', '>=', $time);
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
