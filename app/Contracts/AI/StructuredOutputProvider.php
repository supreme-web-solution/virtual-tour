<?php

namespace App\Contracts\AI;

use App\Services\AI\StructuredRequest;
use App\Services\AI\StructuredResult;

/**
 * A language/vision model that returns JSON matching a supplied schema.
 * Used for property analysis, scene planning and (later) video planning.
 */
interface StructuredOutputProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function generate(StructuredRequest $request): StructuredResult;
}
