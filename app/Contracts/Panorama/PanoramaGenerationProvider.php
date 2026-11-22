<?php

namespace App\Contracts\Panorama;

use App\Services\Panorama\PanoramaStatus;
use App\Services\Panorama\PanoramaStyle;

/**
 * A provider that produces equirectangular 360° panoramas asynchronously.
 */
interface PanoramaGenerationProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Styles the account can use. When $imageToPanorama is true, only styles
     * that support converting a photo into a panorama are returned.
     *
     * @return list<PanoramaStyle>
     */
    public function styles(bool $imageToPanorama = false): array;

    /**
     * Start a text-to-panorama generation.
     */
    public function generate(string $prompt, ?string $negativePrompt, int $styleId, ?string $webhookUrl): PanoramaStatus;

    /**
     * Start converting an ordinary photo (publicly reachable URL) into a panorama.
     */
    public function convertImageToPanorama(string $imageUrl, string $prompt, ?string $negativePrompt, int $styleId, ?string $webhookUrl): PanoramaStatus;

    public function getStatus(string $requestId): PanoramaStatus;

    public function cancel(string $requestId): void;

    /**
     * Verify a webhook delivery and normalise it, or return null when the
     * signature or timestamp is not valid.
     *
     * @param  array<string, string|null>  $headers  Lower-cased header names.
     */
    public function parseWebhook(string $rawBody, array $headers): ?PanoramaStatus;
}
