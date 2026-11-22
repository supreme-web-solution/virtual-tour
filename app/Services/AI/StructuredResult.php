<?php

namespace App\Services\AI;

final readonly class StructuredResult
{
    /**
     * @param  array<string, mixed>  $data  Decoded JSON output (not yet validated by the application).
     */
    public function __construct(
        public array $data,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $costUsd,
        public ?string $providerRequestId = null,
    ) {}
}
