<?php

namespace App\Jobs\Panorama;

use App\Models\AiGeneration;
use App\Services\AI\GenerationLifecycle;
use App\Services\Panorama\PanoramaService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a panorama request to the provider. Transient errors (including the
 * provider's pending-generation limit) are retried with backoff.
 */
class SubmitPanoramaGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    public function __construct(public AiGeneration $generation)
    {
        $this->onQueue('panorama');
    }

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 240, 300, 600, 900];
    }

    public function handle(PanoramaService $panoramas): void
    {
        $panoramas->submit($this->generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail(
            $this->generation,
            'Could not submit the generation: '.($exception?->getMessage() ?? 'unknown error'),
        );
    }
}
