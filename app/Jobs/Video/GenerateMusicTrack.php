<?php

namespace App\Jobs\Video;

use App\Contracts\Music\MusicGenerationProvider;
use App\Enums\GenerationStatus;
use App\Exceptions\ProviderException;
use App\Models\AiGeneration;
use App\Services\AI\GenerationLifecycle;
use App\Services\Video\VideoStudioService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateMusicTrack implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(public AiGeneration $generation)
    {
        $this->onConnection((string) config('queue.long_connection', 'redis-long'));
        $this->onQueue('video-generation');
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
        return [30, 120];
    }

    public function handle(MusicGenerationProvider $provider, VideoStudioService $studio, GenerationLifecycle $lifecycle): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status->isFinal()) {
            return;
        }

        $lifecycle->advance($generation, GenerationStatus::Processing);

        try {
            $seconds = $generation->input('seconds');
            $result = $provider->generate((string) $generation->input('prompt'), is_numeric($seconds) ? (int) $seconds : null);
        } catch (ProviderException $exception) {
            if ($exception->retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }

            $this->failed($exception);

            return;
        }

        $studio->storeMusic($generation, $result);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenerationLifecycle::class)->fail($this->generation, $exception?->getMessage() ?? 'Music generation failed.');

        if ($project = $this->generation->videoProject) {
            app(VideoStudioService::class)->refreshStatus($project);
        }
    }
}
