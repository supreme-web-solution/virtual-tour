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
 * Copies a finished panorama into permanent storage and adds it to the tour.
 */
class StoreGeneratedPanorama implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public AiGeneration $generation)
    {
        $this->onQueue('media');
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
        return [30, 120, 300];
    }

    public function handle(PanoramaService $panoramas): void
    {
        $panoramas->store($this->generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail(
            $this->generation,
            'The panorama was generated but could not be saved: '.($exception?->getMessage() ?? 'unknown error'),
        );
    }
}
