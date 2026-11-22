<?php

namespace App\Services\Video\Render;

final readonly class RenderAudio
{
    /**
     * @param  float  $volume  Linear gain (1.0 = original level).
     */
    public function __construct(
        public string $path,
        public float $volume = 1.0,
        public float $fadeIn = 0.0,
        public float $fadeOut = 0.0,
        public float $trimStart = 0.0,
        public bool $loop = false,
    ) {}
}
