<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A plan limit or missing feature blocked an action. It behaves like any
 * validation error (redirect back / 422), under the "plan" key.
 */
class PlanLimitReachedException extends ValidationException
{
    public static function because(string $message): self
    {
        return self::withMessages(['plan' => $message]);
    }
}
