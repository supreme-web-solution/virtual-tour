<?php

namespace App\Contracts\Video;

use App\Services\Video\Render\RenderResult;
use App\Services\Video\Render\RenderSpec;

/**
 * Assembles clips, transitions and audio into a final MP4.
 */
interface VideoRenderer
{
    public function isAvailable(): bool;

    /**
     * Whether a local media file contains an audio stream.
     */
    public function hasAudio(string $path): bool;

    public function render(RenderSpec $spec): RenderResult;
}
