<?php

namespace App\Enums;

/**
 * How much a person wants to be told about ordinary messages.
 *
 * Emergencies are not covered by any of these — they always get through, and
 * always ignore Do Not Disturb.
 */
enum MessageNotificationLevel: string
{
    case All = 'all';
    case Mentions = 'mentions';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Every message',
            self::Mentions => 'Only when someone mentions me',
            self::None => 'Never',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::All => 'Any message from a colleague in a room you belong to.',
            self::Mentions => 'Quieter. You still see unread counts for everything else.',
            self::None => 'No message pop-ups at all. Emergencies still reach you.',
        };
    }

    public function notifiesEverything(): bool
    {
        return $this === self::All;
    }

    public function isSilent(): bool
    {
        return $this === self::None;
    }
}
