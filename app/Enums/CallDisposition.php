<?php

namespace App\Enums;

/**
 * What should happen when someone is rung.
 *
 * The middle case is the whole point. An emergency overrides Do Not Disturb and
 * an ordinary message obeys it; a call sits between the two, so DND suppresses
 * the *sound* of the ring rather than its existence. The caller is always told
 * which of the three they got, so they can decide whether it warrants the
 * emergency flag instead.
 */
enum CallDisposition: string
{
    case Ring = 'ring';
    case Quiet = 'quiet';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Ring => 'Ringing',
            self::Quiet => 'Ringing quietly',
            self::Blocked => 'Not available',
        };
    }

    public function isQuiet(): bool
    {
        return $this === self::Quiet;
    }
}
