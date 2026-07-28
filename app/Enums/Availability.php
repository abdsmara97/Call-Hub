<?php

namespace App\Enums;

enum Availability: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Away = 'away';
    case OffShift = 'off_shift';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Busy => 'Busy',
            self::Away => 'Away',
            self::OffShift => 'Off shift',
        };
    }

    /** Tailwind token class for the presence dot. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Available => 'bg-presence-available',
            self::Busy => 'bg-presence-busy',
            self::Away => 'bg-presence-away',
            self::OffShift => 'bg-presence-off-shift',
        };
    }
}
