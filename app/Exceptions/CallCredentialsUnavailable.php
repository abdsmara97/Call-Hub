<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The TURN provider could not be reached, or refused us.
 *
 * Deliberately carries no detail from the upstream response: the thing that
 * went wrong is an authenticated call carrying our API token, and the last
 * place that should surface is a JSON body on its way to a browser.
 */
class CallCredentialsUnavailable extends RuntimeException
{
    public static function upstream(int $status): self
    {
        return new self("The TURN provider responded with HTTP {$status}.");
    }

    public static function malformed(): self
    {
        return new self('The TURN provider returned a response we could not read.');
    }
}
