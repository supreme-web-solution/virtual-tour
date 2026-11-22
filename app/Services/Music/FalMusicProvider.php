<?php

namespace App\Services\Music;

use App\Contracts\Music\MusicGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Fal\FalClient;

/**
 * Instrumental background music through fal.ai (ElevenLabs Music by
 * default), with vocals switched off and the length set to the video's.
 *
 * @see https://fal.ai/models/fal-ai/elevenlabs/music/api
 */
class FalMusicProvider implements MusicGenerationProvider
{
    public function __construct(protected FalClient $fal) {}

    public function name(): string
    {
        return 'fal';
    }

    public function model(): string
    {
        return (string) config('ai.providers.fal.music_model');
    }

    public function isConfigured(): bool
    {
        return $this->fal->isConfigured();
    }

    public function generate(string $prompt, ?int $seconds = null): MusicTrackResult
    {
        $input = [
            'prompt' => $prompt,
            'force_instrumental' => true,
            'output_format' => 'mp3_44100_128',
        ];

        if ($seconds !== null) {
            // The model accepts 3 s to 10 min.
            $input['music_length_ms'] = max(3000, min(600000, $seconds * 1000));
        }

        ['request_id' => $requestId, 'result' => $result] = $this->fal->run(
            $this->model(),
            $input,
            (int) config('ai.providers.fal.music_timeout', 300),
        );

        $url = $result['audio']['url'] ?? $result['audio_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new ProviderException('fal.ai did not return any music. The prompt may have been blocked by its safety filter.');
        }

        return new MusicTrackResult(
            path: $this->fal->download($url, 'mp3', 50 * 1024 * 1024),
            mimeType: (string) ($result['audio']['content_type'] ?? 'audio/mpeg'),
            providerRequestId: $requestId,
            costUsd: (float) config('ai.providers.fal.usd_per_music_track', 0),
        );
    }
}
