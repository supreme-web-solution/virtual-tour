<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A URL import step failed. The message is safe to show to the user.
 * $status is the website's HTTP status when it answered with an error.
 */
class ImportException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
