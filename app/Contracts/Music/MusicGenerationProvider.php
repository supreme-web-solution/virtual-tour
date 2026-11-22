<?php

namespace App\Contracts\Music;

use App\Services\Music\MusicTrackResult;

/**
 * Generates original background music. It does not edit or mix video.
 */
interface MusicGenerationProvider
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /**
     * Create an instrumental track, about $seconds long when given. The audio
     * is written to a local temporary file.
     */
    public function generate(string $prompt, ?int $seconds = null): MusicTrackResult;
}
