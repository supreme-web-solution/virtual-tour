<?php

namespace App\Services\Video\Render;

final readonly class RenderResult
{
    public function __construct(
        public string $videoPath,
        public string $posterPath,
        public float $duration,
        public int $width,
        public int $height,
        public bool $hasAudio,
    ) {}
}
