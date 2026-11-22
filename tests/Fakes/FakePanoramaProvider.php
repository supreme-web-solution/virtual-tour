<?php

namespace Tests\Fakes;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Exceptions\ProviderException;
use App\Services\Panorama\PanoramaStatus;
use App\Services\Panorama\PanoramaStyle;

class FakePanoramaProvider implements PanoramaGenerationProvider
{
    /** @var list<array<string, mixed>> */
    public array $submissions = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var array<string, PanoramaStatus> */
    public array $statuses = [];

    public ?ProviderException $submitError = null;

    private int $nextId = 1000;

    public function __construct(public bool $configured = true) {}

    public function name(): string
    {
        return 'skybox';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function styles(bool $imageToPanorama = false): array
    {
        $styles = [
            new PanoramaStyle(10, 'Realistic M3', 'Model 3', 600, 300, false),
            new PanoramaStyle(155, 'Interior M4', 'Model 4', 800, 400, true),
        ];

        return array_values($imageToPanorama ? array_filter($styles, fn ($style) => $style->supportsImageToPanorama) : $styles);
    }

    public function generate(string $prompt, ?string $negativePrompt, int $styleId, ?string $webhookUrl): PanoramaStatus
    {
        return $this->submit(compact('prompt', 'negativePrompt', 'styleId', 'webhookUrl'));
    }

    public function convertImageToPanorama(string $imageUrl, string $prompt, ?string $negativePrompt, int $styleId, ?string $webhookUrl): PanoramaStatus
    {
        return $this->submit(compact('imageUrl', 'prompt', 'negativePrompt', 'styleId', 'webhookUrl'));
    }

    public function getStatus(string $requestId): PanoramaStatus
    {
        return $this->statuses[$requestId] ?? new PanoramaStatus($requestId, PanoramaStatus::PROCESSING);
    }

    public function cancel(string $requestId): void
    {
        $this->cancelled[] = $requestId;
    }

    public function parseWebhook(string $rawBody, array $headers): ?PanoramaStatus
    {
        return null;
    }

    /**
     * Mark a submitted request as finished with a downloadable file.
     */
    public function complete(string $requestId): void
    {
        $this->statuses[$requestId] = new PanoramaStatus($requestId, PanoramaStatus::COMPLETED, "https://cdn.provider.test/{$requestId}.jpg", providerCredits: 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload): PanoramaStatus
    {
        if ($this->submitError) {
            throw $this->submitError;
        }

        $this->submissions[] = $payload;
        $id = (string) $this->nextId++;

        return new PanoramaStatus($id, PanoramaStatus::QUEUED, providerCredits: 3, model: 'Model 4');
    }
}
