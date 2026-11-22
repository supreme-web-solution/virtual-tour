<?php

namespace App\Services\Video\Render;

final readonly class RenderClip
{
    /**
     * @param  float  $duration  Seconds of this clip to use (it is padded if shorter).
     * @param  string  $transition  Transition into the next segment: crossfade|fade_black|cut.
     */
    public function __construct(
        public string $path,
        public float $duration,
        public string $transition,
        public bool $hasAudio,
    ) {}
}
