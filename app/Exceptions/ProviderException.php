<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failed call to an external AI provider.
 *
 * $retryable is true for transient problems (rate limits, timeouts, 5xx) and
 * false for requests the provider will never accept (bad input, no access).
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(string $provider): self
    {
        return new self("{$provider} is not configured. An administrator must add its API key.");
    }
}
