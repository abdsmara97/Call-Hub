<?php

namespace App\Enums;

/**
 * Moderation is per room, not global, so it lives on the membership pivot
 * rather than in the spatie role table.
 */
enum RoomMemberRole: string
{
    case Member = 'member';
    case Moderator = 'moderator';

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Member',
            self::Moderator => 'Moderator',
        };
    }

    public function canModerate(): bool
    {
        return $this === self::Moderator;
    }
}
