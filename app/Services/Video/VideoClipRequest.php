<?php

namespace App\Services\Video;

final readonly class VideoClipRequest
{
    public function __construct(
        public string $prompt,
        public string $imageUrl,
        public int $durationSeconds,
        public string $aspectRatio,
        public ?string $negativePrompt = null,
        // Ask the model for natural ambient sound (only when the video uses it).
        public bool $withAudio = false,
    ) {}
}
