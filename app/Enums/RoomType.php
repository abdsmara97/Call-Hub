<?php

namespace App\Enums;

enum RoomType: string
{
    case Public = 'public';
    case Private = 'private';
    /** A two-person conversation. Modelled as a room so messaging has one code path. */
    case Dm = 'dm';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Public room',
            self::Private => 'Private room',
            self::Dm => 'Direct message',
        };
    }

    public function isConversation(): bool
    {
        return $this === self::Dm;
    }
}
