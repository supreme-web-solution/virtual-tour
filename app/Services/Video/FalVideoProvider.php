<?php

namespace App\Services\Video;

use App\Contracts\Video\VideoGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Fal\FalClient;

/**
 * Image-to-video through fal.ai (Kling 3.0 Pro by default).
 *
 * The clip follows the source image's aspect ratio, so 360° scenes are sent
 * as a flat view already cut to the video's format. Native sound is only
 * requested for "ambient sound" videos.
 *
 * @see https://fal.ai/models/fal-ai/kling-video/v3/pro/image-to-video/api
 */
class FalVideoProvider implements VideoGenerationProvider
{
    public function __construct(protected FalClient $fal) {}

    public function name(): string
    {
        return 'fal';
    }

    public function model(): string
    {
        return (string) config('ai.providers.fal.video_model');
    }

    public function isConfigured(): bool
    {
        return $this->fal->isConfigured();
    }

    public function capabilities(): array
    {
        return [
            'min_seconds' => (int) config('ai.providers.fal.video_min_seconds', 3),
            'max_seconds' => (int) config('ai.providers.fal.video_max_seconds', 10),
            'aspect_ratios' => ['16:9', '9:16', '1:1'],
            'resolution' => (string) config('ai.providers.fal.video_resolution', '1080p'),
            'negative_prompt' => true,
            'prompted_audio' => true,
        ];
    }

    public function generate(VideoClipRequest $request): VideoClipResult
    {
        $capabilities = $this->capabilities();
        $duration = max($capabilities['min_seconds'], min($capabilities['max_seconds'], $request->durationSeconds));

        $input = [
            'prompt' => $request->prompt,
            'start_image_url' => $request->imageUrl,
            'duration' => (string) $duration,
            'generate_audio' => $request->withAudio,
            'negative_prompt' => $request->negativePrompt ?: 'blur, distortion, warping, morphing, people, text, watermark, low quality',
        ];

        ['request_id' => $requestId, 'result' => $result] = $this->fal->run(
            $this->model(),
            $input,
            (int) config('ai.providers.fal.video_timeout', 600),
        );

        $url = $result['video']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new ProviderException('fal.ai did not return a video. The image or prompt may have been blocked by its safety filter.');
        }

        return new VideoClipResult(
            path: $this->fal->download($url, 'mp4'),
            mimeType: (string) ($result['video']['content_type'] ?? 'video/mp4'),
            providerRequestId: $requestId,
            costUsd: round($duration * (float) config('ai.providers.fal.usd_per_video_second', 0), 4),
        );
    }
}
