<?php

namespace App\Exceptions;

use RuntimeException;

class EmergencyRateLimited extends RuntimeException
{
    public static function forSeconds(int $seconds): self
    {
        $wait = $seconds < 60
            ? $seconds.' seconds'
            : ceil($seconds / 60).' minutes';

        return new self(
            "You have already raised an emergency very recently. You can raise another in {$wait}."
        );
    }

    public static function dailyCeiling(int $limit): self
    {
        return new self(
            "You have reached today's limit of {$limit} emergency messages. Contact an administrator if this is a genuine ongoing incident."
        );
    }
}
