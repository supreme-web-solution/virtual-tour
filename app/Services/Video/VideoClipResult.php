<?php

namespace App\Services\Video;

final readonly class VideoClipResult
{
    /**
     * @param  string  $path  Local temporary file holding the generated clip.
     */
    public function __construct(
        public string $path,
        public string $mimeType,
        public ?string $providerRequestId = null,
        public ?float $costUsd = null,
    ) {}
}
