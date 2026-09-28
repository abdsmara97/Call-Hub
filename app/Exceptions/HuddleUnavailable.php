<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The LiveKit SFU could not be reached, or refused us.
 *
 * Like CallCredentialsUnavailable, this deliberately carries no detail from the
 * upstream response — and for a stronger reason. The failing request was signed
 * with the API secret that also signs every join token in the hub, and the last
 * place any of that should surface is a JSON body on its way to a browser.
 */
class HuddleUnavailable extends RuntimeException
{
    public static function upstream(int $status): self
    {
        return new self("The huddle server responded with HTTP {$status}.");
    }

    public static function unreachable(): self
    {
        return new self('The huddle server could not be reached.');
    }

    public static function malformed(): self
    {
        return new self('The huddle server returned a response we could not read.');
    }
}
