<?php

namespace App\Services\Image;

use App\Exceptions\ProviderException;
use App\Services\Fal\FalClient;

/**
 * True 4× upscaling with a super-resolution model on fal.ai (not a
 * generative redraw: it adds pixels, it does not invent objects). Uses the
 * same FAL_KEY as video and music.
 */
class FalUpscaleProvider
{
    public function __construct(protected FalClient $fal) {}

    public function name(): string
    {
        return 'fal';
    }

    public function model(): string
    {
        return (string) config('ai.providers.fal.upscale_model');
    }

    public function isConfigured(): bool
    {
        return $this->fal->isConfigured();
    }

    /**
     * Upscale the picture at an HTTPS URL; the result is a temporary JPEG.
     */
    public function upscale(string $imageUrl): ImageEditResult
    {
        $run = $this->fal->run($this->model(), [
            'image_url' => $imageUrl,
            'scale' => 4,
            'output_format' => 'jpeg',
        ], (int) config('ai.providers.fal.upscale_timeout', 300));

        $url = (string) ($run['result']['image']['url'] ?? '');

        if ($url === '') {
            throw new ProviderException('The upscaler returned no image.', retryable: true);
        }

        $path = $this->fal->download($url, 'jpg', 80 * 1024 * 1024);
        $size = @getimagesize($path);

        return new ImageEditResult(
            path: $path,
            mimeType: 'image/jpeg',
            width: (int) ($size[0] ?? $run['result']['image']['width'] ?? 0),
            height: (int) ($size[1] ?? $run['result']['image']['height'] ?? 0),
            costUsd: (float) config('ai.providers.fal.usd_per_upscale'),
        );
    }
}
