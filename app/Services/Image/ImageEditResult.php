<?php

namespace App\Services\Image;

final readonly class ImageEditResult
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public int $width,
        public int $height,
        public ?float $costUsd = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
