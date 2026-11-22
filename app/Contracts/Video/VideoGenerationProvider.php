<?php

namespace App\Contracts\Video;

use App\Services\Video\VideoClipRequest;
use App\Services\Video\VideoClipResult;

/**
 * An image-to-video model. Providers differ in clip length, aspect ratios,
 * resolutions and audio support, so callers must respect capabilities().
 */
interface VideoGenerationProvider
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /**
     * @return array{min_seconds: int, max_seconds: int, aspect_ratios: list<string>, resolution: string, negative_prompt: bool, prompted_audio: bool}
     */
    public function capabilities(): array;

    /**
     * Animate a still image. The clip is written to a local temporary file.
     */
    public function generate(VideoClipRequest $request): VideoClipResult;
}
