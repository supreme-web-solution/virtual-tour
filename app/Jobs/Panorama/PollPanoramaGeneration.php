<?php

namespace App\Jobs\Panorama;

use App\Contracts\Panorama\PanoramaGenerationProvider;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Services\AI\GenerationLifecycle;
use App\Services\Panorama\PanoramaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fallback status check for when webhooks are not delivered. Re-schedules
 * itself with increasing delays and gives up after a bounded number of checks.
 */
class PollPanoramaGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public AiGeneration $generation)
    {
        $this->onQueue('panorama');
    }

    public function handle(PanoramaGenerationProvider $provider, PanoramaService $panoramas, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinal() || $generation->provider_request_id === null) {
            return;
        }

        // A webhook may already have moved it past the provider stage.
        if (isset($generation->output_metadata['file_url'])) {
            return;
        }

        $generation->increment('poll_attempts');

        try {
            $status = $provider->getStatus($generation->provider_request_id);
        } catch (ProviderException $exception) {
            if (! $exception->retryable) {
                $lifecycle->fail($generation, $exception->getMessage());

                return;
            }

            $status = null;
        }

        if ($status !== null) {
            $panoramas->applyStatus($generation, $status);

            if ($status->isFinished()) {
                return;
            }
        }

        if ($generation->poll_attempts >= (int) config('ai.providers.skybox.max_poll_attempts', 20)) {
            $lifecycle->fail($generation, 'The provider did not finish in time. Check the provider dashboard before retrying; any provider charge is recorded for reconciliation.');

            return;
        }

        self::dispatch($generation)->delay(now()->addSeconds($panoramas->pollDelay($generation->poll_attempts)));
    }
}
