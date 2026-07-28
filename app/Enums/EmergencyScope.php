<?php

namespace App\Enums;

enum EmergencyScope: string
{
    case Room = 'room';
    case Dm = 'dm';
    case Company = 'company';
    case Administration = 'administration';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Room',
            self::Dm => 'Direct message',
            self::Company => 'Whole company',
            self::Administration => 'Whole administration',
            self::All => 'Everyone',
        };
    }

    /** Broadcast scopes are admin-only and take over the recipient's screen. */
    public function isBroadcast(): bool
    {
        return in_array($this, [self::Company, self::Administration, self::All], true);
    }
}
