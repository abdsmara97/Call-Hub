<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The callee cannot be rung at all.
 *
 * Carries a machine-readable reason alongside the sentence shown to the caller,
 * so the client can offer the right follow-up — "send a message instead" makes
 * sense for someone off shift and not for a suspended account.
 */
class CallUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function offShift(string $name): self
    {
        return new self('off_shift', "{$name} is off shift right now.");
    }

    public static function inactive(string $name): self
    {
        return new self('inactive', "{$name} cannot take calls.");
    }

    public static function disabled(): self
    {
        return new self('calls_disabled', 'Calling is currently switched off for this hub.');
    }
}
