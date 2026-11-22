<?php

namespace App\Services\Music;

final readonly class MusicTrackResult
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public ?string $providerRequestId = null,
        public ?float $costUsd = null,
    ) {}
}
