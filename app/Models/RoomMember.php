<?php

namespace App\Models;

use App\Enums\RoomMemberRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Membership pivot. Carries per-room moderation and the read cursor, which is
 * how read receipts are tracked (see the messages migration).
 */
class RoomMember extends Pivot
{
    protected $table = 'room_members';

    public $incrementing = true;

    protected $fillable = [
        'room_id',
        'user_id',
        'role',
        'last_read_message_id',
        'is_muted',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => RoomMemberRole::class,
            'is_muted' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canModerate(): bool
    {
        return $this->role === RoomMemberRole::Moderator;
    }
}
