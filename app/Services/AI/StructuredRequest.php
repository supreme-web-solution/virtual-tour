<?php

namespace App\Services\AI;

final readonly class StructuredRequest
{
    /**
     * @param  list<string>  $imageUrls  Publicly reachable image URLs for vision input.
     * @param  array<string, mixed>  $schema  JSON Schema (strict subset) for the output.
     */
    public function __construct(
        public string $model,
        public string $instructions,
        public string $prompt,
        public string $schemaName,
        public array $schema,
        public array $imageUrls = [],
        public int $maxOutputTokens = 4000,
    ) {}
}
