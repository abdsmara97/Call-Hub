<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    /** Suspended accounts keep their history but cannot authenticate. */
    public function canSignIn(): bool
    {
        return $this === self::Active;
    }
}
